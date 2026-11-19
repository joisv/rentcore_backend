<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\AuditLogService;
use App\Services\ManagerPinVerificationService;
use App\Services\ShiftEnforcementService;
use App\Services\TimezoneService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierController extends Controller
{
    /**
     * Get active payment methods filtered by business and branch configuration.
     */
    public function paymentMethods(Request $request): JsonResponse
    {
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();
        $user = $request->user();

        $branchId = null;
        if ($request->has('branch_id')) {
            $rawBranch = $request->get('branch_id');
            if ($rawBranch !== null && $rawBranch !== '' && $rawBranch !== 'null' && $rawBranch !== 'all') {
                $branchId = (int)$rawBranch;
            }
        } elseif ($request->header('X-Branch-Id')) {
            $branchId = (int)$request->header('X-Branch-Id');
        } elseif ($user?->affiliate_id) {
            $branchId = (int)$user->affiliate_id;
        }

        $cacheKey = $branchId ? "branch_{$branchId}_payment_methods" : "business_{$businessId}_payment_methods";

        $filteredMethods = Cache::remember($cacheKey, 3600, function () use ($businessId, $branchId) {
            $business = $businessId ? \App\Models\Business::find($businessId) : null;
            if (!$business && $branchId) {
                $branch = \App\Models\Affiliate::find($branchId);
                $business = $branch?->business;
            }

            $allMethods = PaymentMethod::where('is_active', true)->get();

            if ($business) {
                $config = $business->getPaymentMethodsConfig($branchId ? (int)$branchId : null);
                $qrisImageUrl = $business->getQrisImageUrl($branchId ? (int)$branchId : null);

                return $allMethods->filter(function ($m) use ($business, $branchId) {
                    return $business->isPaymentMethodEnabled($m, $branchId ? (int)$branchId : null);
                })->map(function ($m) use ($qrisImageUrl, $config) {
                    $item = $m->toArray();
                    if (strtoupper($m->code) === 'QRIS') {
                        $item['qris_image_url'] = $qrisImageUrl;
                        $item['qris_raw_payload'] = $config['qris_raw_payload'] ?? null;
                        $item['is_dynamic_qris_enabled'] = (bool)($config['is_dynamic_qris_enabled'] ?? false);
                    }
                    return $item;
                })->values()->all();
            }

            return $allMethods->toArray();
        });

        return response()->json([
            'success' => true,
            'data' => $filteredMethods,
        ]);
    }

    /**
     * POS Cashier: Process payment for a rental transaction or penalty.
     * Enforces multi-tenant isolation, row locking against double payments,
     * authoritative amount due recalculation, cash change calculation,
     * and immutable financial ledger writing.
     */
    public function processPayment(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|integer|exists:bookings,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payment_type' => 'nullable|in:settlement,penalty,dp,extension',
            'amount' => 'nullable|numeric|min:0',
            'cash_received' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
            'external_reference' => 'nullable|string|max:100',
            'frp_cleared' => 'nullable|boolean',
            'account_removed' => 'nullable|boolean',
            'is_handover_settlement' => 'nullable|boolean',
            'asset_unit_id' => 'nullable|integer|exists:assets,id',
            'collateral_type' => 'nullable|string|max:50',
            'collateral_storage_slot' => 'nullable|string|max:100',
            'start_now' => 'nullable|boolean',
            'keep_original_end_at' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $booking = Booking::withoutGlobalScopes()
            ->with(['customer', 'items.asset.model', 'payments.paymentMethod', 'returnInspection', 'affiliate'])
            ->findOrFail($request->booking_id);

        // Enforce franchise multi-tenant isolation
        if ($user && $user->affiliate_id && !$user->isSuperAdmin() && $booking->affiliate_id !== $user->affiliate_id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses untuk memproses pembayaran cabang lain.',
            ], 403);
        }

        $type = $request->get('payment_type', 'settlement');
        $paymentMethod = PaymentMethod::findOrFail($request->payment_method_id);
        $isCash = strtoupper($paymentMethod->code) === 'CASH';

        // Enforce business & branch payment method availability
        $business = \App\Models\Business::find($booking->business_id);
        if ($business && !$business->isPaymentMethodEnabled($paymentMethod, $booking->affiliate_id)) {
            throw ValidationException::withMessages([
                'payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
            ]);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        if ($idempotencyKey) {
            $existingTx = PaymentTransaction::where('business_id', $booking->business_id ?? $user->business_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingTx) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pembayaran telah diproses sebelumnya (Idempotent).',
                    'data' => [
                        'transaction_id' => $existingTx->id,
                        'transaction_code' => $existingTx->transaction_code,
                        'receipt_number' => $existingTx->receipt_number,
                        'amount' => (float)$existingTx->amount,
                        'cash_received' => (float)$existingTx->cash_received,
                        'cash_change' => (float)$existingTx->cash_change,
                        'status' => $existingTx->status,
                        'payment' => $existingTx->load('paymentMethod'),
                        'is_idempotent' => true,
                    ],
                ]);
            }
        }

        $cashierBranchId = (int)($request->header('X-Branch-Id') ?? $user->affiliate_id ?? $booking->affiliate_id);
        $activeShift = null;
        if ($request->filled('shift_id')) {
            $activeShift = \App\Models\CashDrawerShift::where('id', $request->shift_id)
                ->where('branch_id', $cashierBranchId)
                ->where('status', 'open')
                ->first();
        }
        $activeShift = $activeShift ?? ShiftEnforcementService::requireActiveShift(
            $cashierBranchId,
            $user,
            $isCash,
            'memproses transaksi'
        );

        try {
            return DB::transaction(function () use ($booking, $paymentMethod, $isCash, $type, $request, $user, $idempotencyKey, $cashierBranchId, $activeShift) {
            // Re-fetch booking with row-level pessimistic lock
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['items.asset.model', 'items.assetModel', 'assetModel', 'assetUnit', 'affiliate', 'payments'])
                ->where('id', $booking->id)
                ->lockForUpdate()
                ->first();

            if (
                $request->boolean('is_handover_settlement') &&
                $request->boolean('start_now') &&
                in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover'], true)
            ) {
                $lockedBooking = app(\App\Services\BookingService::class)->applyEarlyPickupShift(
                    $lockedBooking,
                    $request->boolean('keep_original_end_at')
                );
            }

            if ($type === 'settlement' || $type === 'extension' || $type === 'dp') {
                $alreadyPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange', 'deposit'])
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'void');
                    })
                    ->sum('amount');
                $remainingDue = max(0, (float)$lockedBooking->total_bill - $alreadyPaid);

                if ($remainingDue <= 0 && $lockedBooking->payment_status === 'paid') {
                    throw ValidationException::withMessages([
                        'booking' => ['Transaksi sewa ini sudah lunas sebelumnya.'],
                    ]);
                }

                $amountToPay = $request->filled('amount') ? min((float)$request->amount, $remainingDue) : $remainingDue;
            } elseif ($type === 'penalty') {
                $inspection = $lockedBooking->returnInspection;
                if (!$inspection || (float)$inspection->total_penalty <= 0) {
                    throw ValidationException::withMessages([
                        'booking' => ['Tidak ada tagihan denda untuk transaksi ini.'],
                    ]);
                }
                $penaltyPaid = (float)$lockedBooking->payments()
                    ->where('type', 'penalty')
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'void');
                    })
                    ->sum('amount');
                $remainingPenalty = max(0, (float)$inspection->total_penalty - $penaltyPaid);

                if ($remainingPenalty <= 0) {
                    throw ValidationException::withMessages([
                        'booking' => ['Denda untuk transaksi ini sudah lunas.'],
                    ]);
                }

                $amountToPay = $request->filled('amount') ? min((float)$request->amount, $remainingPenalty) : $remainingPenalty;
            } else {
                $amountToPay = (float)($request->amount ?? $lockedBooking->total_bill);
            }

            // Cash validation & change calculation
            if ($isCash) {
                if (!isset($request->cash_received)) {
                    throw ValidationException::withMessages([
                        'cash_received' => ['Jumlah uang tunai yang diterima wajib diisi.'],
                    ]);
                }
                $cashReceived = (float)$request->cash_received;
                if ($cashReceived < $amountToPay) {
                    throw ValidationException::withMessages([
                        'cash_received' => [
                            'Jumlah uang tunai yang diterima (Rp ' . number_format($cashReceived, 0, ',', '.') . 
                            ') kurang dari total tagihan (Rp ' . number_format($amountToPay, 0, ',', '.') . ').'
                        ],
                    ]);
                }
                $cashChange = max(0, $cashReceived - $amountToPay);
            } else {
                $cashReceived = $amountToPay;
                $cashChange = 0.00;
            }

            $datePrefix = Carbon::now()->format('ymd');
            $txEntropy = strtoupper(\Illuminate\Support\Str::random(4));
            $txBusinessId = $booking->business_id ?? $user->business_id;
            $txCount = (int)PaymentTransaction::where('business_id', $txBusinessId)->count() + 1;
            $txCode = sprintf('TX-%s-%04d-%s', $datePrefix, $txCount, $txEntropy);
            $receiptNumber = sprintf('REC-%s-%s-%s', $booking->affiliate->code ?? 'PST', $datePrefix, strtoupper(\Illuminate\Support\Str::random(6)));

            $recordedDeposit = (float)$lockedBooking->payments()
                ->where('type', 'deposit')
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'void'))
                ->sum('amount');
            $remainingDeposit = ($type === 'settlement' && (float)$lockedBooking->deposit_fee > 0)
                ? max(0.0, round((float)$lockedBooking->deposit_fee - $recordedDeposit, 2))
                : 0.0;
            $depositAmount = min($amountToPay, $remainingDeposit);
            $primaryTxAmount = max(0.0, round($amountToPay - $depositAmount, 2));

            $paymentTx = null;
            if ($primaryTxAmount > 0 || $depositAmount <= 0) {
                $paymentTx = PaymentTransaction::create([
                    'business_id' => $booking->business_id ?? $user->business_id,
                    'branch_id' => $cashierBranchId ?: null,
                    'shift_id' => $activeShift?->id,
                    'transaction_code' => $txCode,
                    'receipt_number' => $receiptNumber,
                    'external_reference' => $request->input('external_reference'),
                    'idempotency_key' => $idempotencyKey,
                    'booking_id' => $booking->id,
                    'payment_method_id' => $paymentMethod->id,
                    'processed_by' => $user->id,
                    'type' => $type,
                    'status' => 'completed',
                    'direction' => $type === 'refund' ? 'outflow' : 'inflow',
                    'amount' => $primaryTxAmount > 0 ? $primaryTxAmount : $amountToPay,
                    'cash_received' => $isCash ? max($primaryTxAmount, $cashReceived - $depositAmount) : $primaryTxAmount,
                    'cash_change' => $cashChange,
                    'notes' => $request->notes ?? ($type === 'penalty' ? 'Pelunasan denda via Kasir POS' : ($type === 'extension' ? 'Pembayaran perpanjangan sewa via Kasir POS' : 'Pembayaran sewa via Kasir POS')),
                    'transacted_at' => Carbon::now(),
                ]);
            }

            if ($depositAmount > 0) {
                $depTxCount = (int)PaymentTransaction::where('business_id', $txBusinessId)->count() + 1;
                $depTx = PaymentTransaction::create([
                    'business_id' => $booking->business_id ?? $user->business_id,
                    'branch_id' => $cashierBranchId ?: null,
                    'shift_id' => $activeShift?->id,
                    'transaction_code' => sprintf('TX-DEP-%s-%04d-%s', $datePrefix, $depTxCount, strtoupper(\Illuminate\Support\Str::random(4))),
                    'receipt_number' => sprintf('REC-DEP-%s-%s-%s', $booking->affiliate->code ?? 'PST', $datePrefix, strtoupper(\Illuminate\Support\Str::random(6))),
                    'idempotency_key' => $paymentTx ? null : $idempotencyKey,
                    'booking_id' => $booking->id,
                    'payment_method_id' => $paymentMethod->id,
                    'processed_by' => $user->id,
                    'type' => 'deposit',
                    'status' => 'completed',
                    'direction' => 'inflow',
                    'amount' => $depositAmount,
                    'cash_received' => $depositAmount,
                    'cash_change' => 0.00,
                    'notes' => 'Uang titipan jaminan sewa (deposit)',
                    'transacted_at' => Carbon::now(),
                ]);
                $paymentTx ??= $depTx;
            }

            // Update booking payment status
            $handoverCompleted = false;
            if ($type === 'settlement' || $type === 'extension' || $type === 'dp') {
                $newTotalPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange', 'deposit'])
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'void');
                    })
                    ->sum('amount');
                $newPaymentStatus = ($newTotalPaid >= (float)$lockedBooking->total_bill) ? 'paid' : ($newTotalPaid > 0 ? 'partial' : 'unpaid');
                $lockedBooking->update([
                    'payment_status' => $newPaymentStatus,
                    'remaining_balance' => max(0.0, round((float)$lockedBooking->total_bill - $newTotalPaid, 2)),
                ]);

                if (
                    $request->boolean('is_handover_settlement') &&
                    in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover'], true)
                ) {
                    if ($newPaymentStatus !== 'paid') {
                        throw ValidationException::withMessages([
                            'amount' => ['Pembayaran pelunasan harus mencukupi sisa tagihan sebelum menyerahkan unit.'],
                        ]);
                    }

                    $lockedBooking = app(\App\Services\BookingService::class)->checkInBooking(
                        $lockedBooking,
                        [
                            'asset_unit_id' => $request->input('asset_unit_id'),
                            'collateral_type' => $request->input('collateral_type'),
                            'collateral_storage_slot' => $request->input('collateral_storage_slot'),
                        ],
                        $user
                    );
                    $handoverCompleted = true;
                }
            } elseif ($type === 'penalty') {
                $totalPenaltyPaid = (float)$lockedBooking->payments()
                    ->where('type', 'penalty')
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'void');
                    })
                    ->sum('amount');
                $totalPenaltyDue = (float)($lockedBooking->returnInspection?->total_penalty ?? 0.0);
                $isRentalSettled = ((float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange', 'deposit'])
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'void');
                    })
                    ->sum('amount')) >= (float)$lockedBooking->total_bill;
                if ($isRentalSettled && $totalPenaltyPaid >= $totalPenaltyDue) {
                    $lockedBooking->update(['payment_status' => 'paid']);
                }
            }

            // Security Audit Log
            AuditLogService::log(
                $user,
                'cashier.payment_processed',
                'PaymentTransaction',
                $txCode,
                [
                    'booking_code' => $booking->booking_code,
                    'amount' => $amountToPay,
                    'method' => $paymentMethod->name,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'type' => $type,
                ],
                null,
                request()?->ip(),
                $booking->affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran kasir POS berhasil diproses.',
                'data' => [
                    'payment' => $paymentTx->load('paymentMethod'),
                    'booking' => $lockedBooking->fresh(['customer', 'payments.paymentMethod', 'items.asset.model', 'assetModel', 'assetUnit']),
                    'amount_due' => $amountToPay,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'receipt_number' => $receiptNumber,
                    'handover_completed' => $handoverCompleted,
                ],
            ], 201);
        });
        } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
            if ($idempotencyKey) {
                $existingTx = PaymentTransaction::where('business_id', $booking->business_id ?? $user->business_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existingTx) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Pembayaran telah diproses sebelumnya (Idempotent).',
                        'data' => [
                            'transaction_id' => $existingTx->id,
                            'transaction_code' => $existingTx->transaction_code,
                            'receipt_number' => $existingTx->receipt_number,
                            'amount' => (float)$existingTx->amount,
                            'cash_received' => (float)$existingTx->cash_received,
                            'cash_change' => (float)$existingTx->cash_change,
                            'status' => $existingTx->status,
                            'payment' => $existingTx->load('paymentMethod'),
                            'is_idempotent' => true,
                        ],
                    ]);
                }
            }
            throw $e;
        }
    }

    /**
     * Get thermal receipt data for a payment transaction.
     */
    public function receipt(string $id): JsonResponse
    {
        $user = request()->user();
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();
        $isSuperAdmin = $user && $user->isSuperAdmin();

        $tx = PaymentTransaction::with([
            'paymentMethod',
            'booking.customer',
            'booking.items.asset.model',
            'booking.affiliate',
            'booking.voucher',
            'processedBy',
        ])->where('id', $id)->orWhere('receipt_number', $id)->firstOrFail();

        // Enforce business isolation
        if ($businessId && $tx->business_id && (int)$tx->business_id !== (int)$businessId && !$isSuperAdmin && !$user->isPlatformAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Transaksi bukan milik bisnis rental ini.',
            ], 403);
        }

        // Enforce branch isolation for branch staff
        if ($user && !$isSuperAdmin && !$user->isPlatformAdmin()) {
            $member = $tenantContext->getMember() ?? ($businessId ? $user->getBusinessMembership($businessId) : null);
            $isOwner = $member ? $member->isOwner() : false;

            if (!$isOwner) {
                $allowedBranches = $user->getBranchIdsForBusiness($businessId);
                if (empty($allowedBranches) && $user->affiliate_id) {
                    $allowedBranches = [(int)$user->affiliate_id];
                }
                // If the booking is not visible (filtered by the branch-restricted TenantScope),
                // it belongs to another branch: deny instead of dereferencing null.
                $txBranchId = $tx->booking ? (int)$tx->booking->affiliate_id : null;
                if ((!empty($allowedBranches) || $tenantContext->isBranchRestricted())
                    && ($txBranchId === null || !in_array($txBranchId, array_map('intval', $allowedBranches), true))) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki hak akses struk cabang lain.',
                    ], 403);
                }
            }
        }

        $affiliate = $tx->booking->affiliate;
        $items = $tx->booking->items->map(function ($item) {
            return [
                'name' => $item->asset->model->name ?? 'Perangkat Apple',
                'asset_code' => $item->asset->asset_code ?? '',
                'price' => (float)$item->unit_price,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'receipt_number' => $tx->receipt_number,
                'transaction_code' => $tx->transaction_code,
                'outlet_name' => $affiliate->name ?? 'RentCore Central Hub',
                'outlet_address' => $affiliate->address ?? 'Pusat Rental Elektronik',
                'outlet_phone' => $affiliate->phone ?? '0812-3456-7890',
                'transacted_at' => $tx->transacted_at,
                'cashier_name' => $tx->processedBy->name ?? 'Kasir RentCore',
                'customer_name' => $tx->booking->customer->name ?? 'Pelanggan',
                'customer_phone' => $tx->booking->customer->phone ?? '',
                'customer_address' => $tx->booking->customer->address ?? '',
                'booking_code' => $tx->booking->booking_code,
                'duration_hours' => $tx->booking->duration_hours,
                'items' => $items,
                'subtotal' => (float)$tx->booking->subtotal_amount,
                'discount_amount' => (float)$tx->booking->discount_amount,
                'voucher_code' => $tx->booking->voucher->code ?? null,
                'total_amount' => (float)$tx->amount,
                'payment_method' => $tx->paymentMethod->name ?? 'Tunai',
                'type' => $tx->type,
                'type_label' => $tx->type === 'penalty'
                    ? 'Denda Keterlambatan (Late Return Penalty)'
                    : ($tx->type === 'extension' ? 'Perpanjangan Sewa' : 'Sewa Kontrak Pokok'),
                'cash_received' => (float)$tx->cash_received,
                'cash_change' => (float)$tx->cash_change,
            ],
        ]);
    }

    /**
     * Get closing shift summary for today or given date range.
     */
    public function closingSummary(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $dateParam = $request->get('date');

        $user = $request->user();
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();
        $isSuperAdmin = $user && $user->isSuperAdmin();

        $business = $businessId ? \App\Models\Business::find($businessId) : null;
        $tz = $business?->timezone ?? 'Asia/Jakarta';
        $bounds = TimezoneService::resolveQueryBounds($startDate, $endDate, $dateParam, $tz);
        $start = $bounds['start_utc'];
        $end = $bounds['end_utc'];
        $dateLabel = $bounds['date_label'];

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwner = $member ? $member->isOwner() : ($isSuperAdmin || ($user && $user->isPlatformAdmin()));
        $isCentralBranchStaff = false;
        if ($user && $user->affiliate_id) {
            $userAffiliate = \App\Models\Affiliate::find($user->affiliate_id);
            $isCentralBranchStaff = (bool)($userAffiliate?->is_central ?? false);
        }
        $canAccessConsolidated = $isOwner || $isSuperAdmin || $isCentralBranchStaff;

        // Resolve requested branch from query parameter or header
        $isAllBranches = $request->boolean('all_branches')
            || $request->get('branch_id') === 'all'
            || $request->get('branch_id') === '-1';

        $rawBranchId = $isAllBranches ? null : ($request->get('branch_id') ?? $request->get('affiliate_id') ?? $request->header('X-Branch-Id'));
        $requestedBranchId = ($rawBranchId !== null && $rawBranchId !== '' && $rawBranchId !== 'all') ? (int)$rawBranchId : null;
        $targetBranchId = null;

        // Authorize branch scope
        if (!$canAccessConsolidated) {
            $allowedBranches = $user ? $user->getBranchIdsForBusiness($businessId) : [];
            if (empty($allowedBranches) && $user && $user->affiliate_id) {
                $allowedBranches = [(int)$user->affiliate_id];
            }

            if ($isAllBranches) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses cabang ditolak. Anda tidak berwenang melihat laporan konsolidasi seluruh cabang.',
                    'code' => 'BRANCH_ACCESS_DENIED',
                ], 403);
            }

            if ($requestedBranchId !== null) {
                if (!in_array($requestedBranchId, $allowedBranches, true)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Akses cabang ditolak. Anda tidak berwenang melihat laporan closing cabang lain.',
                        'code' => 'BRANCH_ACCESS_DENIED',
                    ], 403);
                }
                $targetBranchId = $requestedBranchId;
            } else {
                $targetBranchId = !empty($allowedBranches) ? $allowedBranches[0] : ($user && $user->affiliate_id ? (int)$user->affiliate_id : null);
            }
        } else {
            // Business Owner / Platform Admin / Central Staff: specific branch or consolidated (null)
            if (!$isAllBranches && $requestedBranchId !== null) {
                if ($businessId) {
                    $branchValid = \App\Models\Affiliate::where('id', $requestedBranchId)
                        ->where('business_id', $businessId)
                        ->where('is_active', true)
                        ->exists();

                    if (!$branchValid) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cabang tidak valid untuk bisnis rental ini.',
                            'code' => 'INVALID_BRANCH',
                        ], 422);
                    }
                }
                $targetBranchId = $requestedBranchId;
            } else {
                // Explicit all_branches or no branch specified by authorized central/owner
                if (!$isAllBranches && !$businessId && $user && $user->affiliate_id && !$isSuperAdmin) {
                    $targetBranchId = (int)$user->affiliate_id;
                } else {
                    $targetBranchId = null; // Consolidated
                }
            }
        }

        $baseQuery = PaymentTransaction::whereBetween('payment_transactions.transacted_at', [$start, $end]);

        if ($businessId) {
            $baseQuery->where(function ($q) use ($businessId) {
                $q->where('payment_transactions.business_id', $businessId)
                  ->orWhereHas('booking', function ($bq) use ($businessId) {
                      $bq->where('business_id', $businessId);
                  });
            });
        }

        if ($targetBranchId !== null) {
            $baseQuery->where(function ($q) use ($targetBranchId) {
                $q->where('payment_transactions.branch_id', $targetBranchId)
                  ->orWhere(function ($fallbackQ) use ($targetBranchId) {
                      $fallbackQ->whereNull('payment_transactions.branch_id')
                                ->whereHas('booking', fn($bq) => $bq->where('affiliate_id', $targetBranchId));
                  });
            });
        }

        $totalTransactions = (clone $baseQuery)->count();

        // Lightweight selection for summary metrics: loads only necessary scalar columns
        // Avoiding hydration of thousands of deep booking.items.asset.model relationships
        $txSummaryList = (clone $baseQuery)
            ->select([
                'payment_transactions.id',
                'payment_transactions.amount',
                'payment_transactions.type',
                'payment_transactions.status',
                'payment_transactions.direction',
                'payment_transactions.payment_method_id',
                'payment_transactions.branch_id',
                'payment_transactions.booking_id',
            ])
            ->with([
                'paymentMethod:id,name,code',
                'booking' => fn($q) => $q->withoutGlobalScope(\App\Scopes\TenantScope::class)->select(['id', 'affiliate_id']),
            ])
            ->get();

        $breakdown = [];
        $totalRevenue = 0.00;

        $branchStats = [];
        if ($businessId) {
            $affiliates = \App\Models\Affiliate::where('business_id', $businessId)
                ->where('is_active', true)
                ->get();
            foreach ($affiliates as $aff) {
                $branchStats[$aff->id] = [
                    'branch_id' => $aff->id,
                    'branch_name' => $aff->name,
                    'branch_code' => $aff->code,
                    'is_central' => (bool)$aff->is_central,
                    'transaction_count' => 0,
                    'total_revenue' => 0.00,
                    'cash_revenue' => 0.00,
                    'non_cash_revenue' => 0.00,
                ];
            }
        }

        $typeBreakdown = [
            'rental' => ['label' => 'Sewa Kontrak Pokok', 'count' => 0, 'total' => 0.00],
            'extension' => ['label' => 'Perpanjangan Sewa', 'count' => 0, 'total' => 0.00],
            'penalty' => ['label' => 'Denda Keterlambatan (Late Return Penalty)', 'count' => 0, 'total' => 0.00],
            'refund' => ['label' => 'Pengembalian Dana / Titipan Jaminan (Refund)', 'count' => 0, 'total' => 0.00],
        ];

        // Pre-cache tenant affiliates to avoid in-loop queries
        $affiliatesMap = $businessId
            ? \App\Models\Affiliate::where('business_id', $businessId)->get()->keyBy('id')
            : collect();

        $drawerCashInflow = 0.00;
        $drawerCashOutflow = 0.00;

        foreach ($txSummaryList as $tx) {
            // Exclude void transactions from revenue and drawer calculations
            if (($tx->status ?? 'completed') === 'void') {
                continue;
            }

            $amount = (float)$tx->amount;
            $methodName = $tx->paymentMethod->name ?? 'Lainnya';
            $methodCode = strtolower($tx->paymentMethod->code ?? '');
            $isCash = $methodCode === 'cash' || str_contains(strtolower($methodName), 'tunai');

            // Security deposits (`deposit` / `deposit_refund`) affect physical cash drawer balance
            // when settled in cash, but are excluded from net revenue calculations.
            if (in_array($tx->type, ['deposit', 'deposit_refund'], true)) {
                if ($isCash) {
                    if ($tx->type === 'deposit') {
                        $drawerCashInflow += $amount;
                    } else {
                        $drawerCashOutflow += $amount;
                    }
                }
                continue;
            }

            $isRefund = ($tx->type === 'refund' || ($tx->direction ?? 'inflow') === 'outflow');

            if ($isCash) {
                if ($isRefund) {
                    $drawerCashOutflow += $amount;
                } else {
                    $drawerCashInflow += $amount;
                }
            }

            if ($isRefund) {
                $totalRevenue -= $amount;
            } else {
                $totalRevenue += $amount;
            }

            $txType = $tx->type ?? 'settlement';
            if ($txType === 'penalty') {
                $typeBreakdown['penalty']['count']++;
                $typeBreakdown['penalty']['total'] += $amount;
            } elseif ($txType === 'extension') {
                $typeBreakdown['extension']['count']++;
                $typeBreakdown['extension']['total'] += $amount;
            } elseif ($txType === 'refund') {
                $typeBreakdown['refund']['count']++;
                $typeBreakdown['refund']['total'] += $amount;
            } else {
                $typeBreakdown['rental']['count']++;
                $typeBreakdown['rental']['total'] += $amount;
            }

            if (!isset($breakdown[$methodName])) {
                $breakdown[$methodName] = [
                    'count' => 0,
                    'total' => 0.00,
                ];
            }
            $breakdown[$methodName]['count']++;
            if ($isRefund) {
                $breakdown[$methodName]['total'] -= $amount;
            } else {
                $breakdown[$methodName]['total'] += $amount;
            }

            $bId = $tx->branch_id ?? $tx->booking?->affiliate_id;
            if ($bId !== null) {
                if (!isset($branchStats[$bId])) {
                    $branchModel = $affiliatesMap->get($bId) ?? \App\Models\Affiliate::find($bId);
                    $bName = $branchModel?->name ?? ($tx->booking?->affiliate?->name ?? ('Cabang #' . $bId));
                    $bCode = $branchModel?->code ?? ($tx->booking?->affiliate?->code ?? 'CBG');
                    $isCentral = (bool)($branchModel?->is_central ?? ($tx->booking?->affiliate?->is_central ?? false));
                    $branchStats[$bId] = [
                        'branch_id' => $bId,
                        'branch_name' => $bName,
                        'branch_code' => $bCode,
                        'is_central' => $isCentral,
                        'transaction_count' => 0,
                        'total_revenue' => 0.00,
                        'cash_revenue' => 0.00,
                        'non_cash_revenue' => 0.00,
                    ];
                }
                $branchStats[$bId]['transaction_count']++;
                if ($isRefund) {
                    $branchStats[$bId]['total_revenue'] -= $amount;
                    if ($isCash) {
                        $branchStats[$bId]['cash_revenue'] -= $amount;
                    } else {
                        $branchStats[$bId]['non_cash_revenue'] -= $amount;
                    }
                } else {
                    $branchStats[$bId]['total_revenue'] += $amount;
                    if ($isCash) {
                        $branchStats[$bId]['cash_revenue'] += $amount;
                    } else {
                        $branchStats[$bId]['non_cash_revenue'] += $amount;
                    }
                }
            }
        }

        $branchBreakdownList = array_values($branchStats);
        if ($targetBranchId !== null) {
            $branchBreakdownList = array_values(array_filter($branchBreakdownList, fn($b) => $b['branch_id'] === $targetBranchId));
        }

        // Fetch Branch Operating Expenses in this period
        $expenseQuery = \App\Models\BranchExpense::whereBetween(DB::raw('COALESCE(branch_expenses.disbursed_at, branch_expenses.created_at)'), [$start, $end])
            ->whereIn('status', ['approved', 'disbursed']);

        if ($businessId) {
            $expenseQuery->where('business_id', $businessId);
        }
        if ($targetBranchId !== null) {
            $expenseQuery->where('branch_id', $targetBranchId);
        }

        $expenses = $expenseQuery->latest('created_at')->get();
        $totalExpenses = 0.00;
        $cashDrawerExpenses = 0.00;
        $expenseCategoryBreakdown = [];

        foreach ($expenses as $exp) {
            if ($exp->status === 'void') {
                continue;
            }
            $expAmt = (float)$exp->amount;
            $totalExpenses += $expAmt;
            if ($exp->payment_source === 'cash_drawer') {
                $cashDrawerExpenses += $expAmt;
            }
            $cat = $exp->category ?? 'other';
            if (!isset($expenseCategoryBreakdown[$cat])) {
                $expenseCategoryBreakdown[$cat] = ['count' => 0, 'total' => 0.00];
            }
            $expenseCategoryBreakdown[$cat]['count']++;
            $expenseCategoryBreakdown[$cat]['total'] += $expAmt;
        }

        // Active shift & opening float if single branch
        $activeShiftData = null;
        $openingFloat = 0.00;
        $hasOpenShift = false;
        if ($targetBranchId !== null) {
            $activeShift = \App\Models\CashDrawerShift::where('branch_id', $targetBranchId)
                ->where('status', 'open')
                ->latest('opened_at')
                ->first();

            if ($activeShift) {
                $hasOpenShift = true;
                $openingFloat = (float)$activeShift->opening_cash_float;

                // True Blind Close: redact expected_cash_now and cash movement totals while shift is open
                $activeShiftData = [
                    'id' => $activeShift->id,
                    'shift_number' => $activeShift->shift_number,
                    'opened_at' => $activeShift->opened_at->toIso8601String(),
                    'opening_cash_float' => $openingFloat,
                    'status' => $activeShift->status,
                    'is_blind_close' => true,
                ];
            }
        }

        $cashReconciliation = [
            'opening_cash_float' => (float)$openingFloat,
            'net_cash_revenue' => (float)($drawerCashInflow - $drawerCashOutflow),
            'cash_drawer_expenses' => (float)$cashDrawerExpenses,
            'active_shift' => $activeShiftData,
        ];

        // Only expose expected_cash_in_drawer when no open shift is enforcing blind close.
        // Aggregate opening_cash_float across all shifts in the date range + cash inflows - outflows - cash_drawer expenses.
        if (!$hasOpenShift) {
            $periodShifts = \App\Models\CashDrawerShift::query()
                ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                ->when($targetBranchId !== null, fn ($q) => $q->where('branch_id', $targetBranchId))
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('opened_at', [$start, $end])
                      ->orWhereBetween('closed_at', [$start, $end]);
                })
                ->get();

            $openingFloat = (float)$periodShifts->sum('opening_cash_float');
            $periodShiftIds = $periodShifts->pluck('id')->all();
            $periodDrawerExpenses = !empty($periodShiftIds)
                ? (float)\App\Models\BranchExpense::whereIn('shift_id', $periodShiftIds)
                    ->where('payment_source', 'cash_drawer')
                    ->where('status', 'disbursed')
                    ->sum('amount')
                : (float)$cashDrawerExpenses;

            $cashReconciliation['opening_cash_float'] = $openingFloat;
            $cashReconciliation['cash_drawer_expenses'] = $periodDrawerExpenses;
            $cashReconciliation['expected_cash_in_drawer'] = (float)max(
                0.00,
                $openingFloat + $drawerCashInflow - $drawerCashOutflow - $periodDrawerExpenses
            );
        }

        $limit = max(10, min(500, (int)$request->get('limit', $request->get('per_page', 50))));
        $recentTransactions = (clone $baseQuery)
            ->with([
                'paymentMethod',
                'branch',
                'booking' => fn($q) => $q->withoutGlobalScope(\App\Scopes\TenantScope::class),
                'booking.affiliate',
                'booking.customer',
                'booking.items.asset.model',
                'processedBy',
                'voidBy'
            ])
            ->latest('payment_transactions.transacted_at')
            ->limit($limit)
            ->get()
            ->map(function ($tx) {
            return [
                'id' => $tx->id,
                'transaction_code' => $tx->transaction_code,
                'receipt_number' => $tx->receipt_number,
                'booking_id' => $tx->booking_id,
                'booking_code' => $tx->booking?->booking_code,
                'customer_name' => $tx->booking?->customer?->name ?? 'Pelanggan',
                'customer_phone' => $tx->booking?->customer?->phone ?? '',
                'branch_id' => $tx->branch_id ?? $tx->booking?->affiliate_id,
                'branch_name' => $tx->branch?->name ?? ($tx->booking?->affiliate?->name ?? ($tx->booking?->affiliate_id ? 'Cabang #' . $tx->booking->affiliate_id : 'Tidak Teridentifikasi')),
                'is_central' => (bool)($tx->branch?->is_central ?? ($tx->booking?->affiliate?->is_central ?? false)),
                'status' => $tx->status ?? 'completed',
                'direction' => $tx->direction ?? ($tx->type === 'refund' ? 'outflow' : 'inflow'),
                'cashier_name' => $tx->processedBy?->name ?? 'Kasir',
                'amount' => (float)$tx->amount,
                'payment_method' => $tx->paymentMethod?->name ?? 'Tunai',
                'type' => $tx->type,
                'type_label' => match ($tx->type) {
                    'penalty' => 'Denda Keterlambatan (Late Return Penalty)',
                    'extension' => 'Perpanjangan Sewa',
                    'refund' => 'Pengembalian Dana / Deposit (Refund)',
                    'deposit' => 'Titipan Jaminan (Deposit)',
                    'deposit_refund' => 'Pengembalian Deposit Jaminan',
                    'dp' => 'Uang Muka (DP)',
                    default => 'Sewa Kontrak Pokok',
                },
                'cash_received' => (float)$tx->cash_received,
                'cash_change' => (float)$tx->cash_change,
                'notes' => $tx->notes,
                'transacted_at' => $tx->transacted_at?->toISOString() ?? (string)$tx->transacted_at,
                'void_reason' => $tx->void_reason,
                'void_by' => $tx->voidBy?->name,
                'items_summary' => $tx->booking?->items?->map(function ($item) {
                    $mName = $item->asset?->model?->name ?? 'Perangkat Apple';
                    $sn = $item->asset?->serial_number;
                    $imei = $item->asset?->imei_1;
                    $desc = $mName;
                    if ($sn) $desc .= ' (SN: ' . $sn . ')';
                    elseif ($imei) $desc .= ' (IMEI: ' . $imei . ')';
                    return $desc;
                })->join(', '),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'branch_id' => $targetBranchId,
                'is_consolidated' => $targetBranchId === null,
                'date' => $dateLabel,
                'start_date' => $bounds['start_date'],
                'end_date' => $bounds['end_date'],
                'timezone' => $tz,
                'total_revenue' => (float)$totalRevenue,
                'total_transactions' => (int)$totalTransactions,
                'total_expenses' => (float)$totalExpenses,
                'cash_drawer_expenses' => (float)$cashDrawerExpenses,
                'expense_breakdown' => (object)$expenseCategoryBreakdown,
                'cash_reconciliation' => $cashReconciliation,
                'breakdown_by_type' => (object)$typeBreakdown,
                'breakdown_by_method' => (object)$breakdown,
                'breakdown_by_branch' => $branchBreakdownList,
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }

    /**
     * Void a payment transaction.
     * Enforces authority (Manager PIN or Owner/Admin role), marks status void,
     * recalibrates linked booking payment status, and writes security audit log.
     */
    public function voidTransaction(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:5|max:255',
            'manager_pin' => 'nullable|string',
        ]);

        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? $user->current_business_id ?? $user->business_id;

        $transaction = PaymentTransaction::where('id', $id)
            ->where('business_id', $businessId)
            ->firstOrFail();

        if ($transaction->status === 'void') {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi ini sudah dibatalkan (void) sebelumnya.',
            ], 422);
        }

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->role === 'admin') : ($user->isSuperAdmin() || $user->isOwner());

        $voidedByUserId = $user->id;

        if (!$isOwnerOrAdmin) {
            $branchId = $transaction->branch_id ?? $user->affiliate_id;
            $matchingManager = ManagerPinVerificationService::verify(
                $user,
                $request->manager_pin,
                $businessId ? (int)$businessId : null,
                $branchId ? (int)$branchId : null,
                'Pembatalan transaksi pembayaran kasir wajib disetujui menggunakan PIN Manager Cabang.',
                $request->ip(),
                'PaymentTransaction'
            );
            $voidedByUserId = $matchingManager->id;
        }

        return DB::transaction(function () use ($transaction, $request, $voidedByUserId, $user) {
            $transaction->status = 'void';
            $transaction->void_reason = $request->reason;
            $transaction->void_by = $voidedByUserId;
            $transaction->save();

            // Authoritatively recalculate linked booking remaining_balance & payment_status
            if ($transaction->booking_id) {
                $booking = Booking::withoutGlobalScopes()->lockForUpdate()->find($transaction->booking_id);
                if ($booking) {
                    $booking->recalculateFinancials();
                }
            }

            AuditLogService::log(
                $user,
                'cashier.transaction_voided',
                'PaymentTransaction',
                $transaction->transaction_code,
                [
                    'reason' => $request->reason,
                    'amount' => (float)$transaction->amount,
                    'void_by' => $voidedByUserId,
                    'booking_id' => $transaction->booking_id,
                ],
                null,
                request()?->ip(),
                $transaction->branch_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Transaksi ' . ($transaction->receipt_number ?? $transaction->transaction_code) . ' berhasil dibatalkan (void).',
                'data' => [
                    'id' => $transaction->id,
                    'transaction_code' => $transaction->transaction_code,
                    'status' => $transaction->status,
                    'void_reason' => $transaction->void_reason,
                    'void_by' => $transaction->voidBy?->name ?? 'Manager',
                ],
            ]);
        });
    }
}
