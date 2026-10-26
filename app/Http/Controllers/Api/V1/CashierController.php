<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TimezoneService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CashierController extends Controller
{
    /**
     * Get active payment methods.
     */
    public function paymentMethods(): JsonResponse
    {
        $methods = PaymentMethod::where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => $methods,
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

        try {
            return DB::transaction(function () use ($booking, $paymentMethod, $isCash, $type, $request, $user, $idempotencyKey) {
            // Re-fetch booking with row-level pessimistic lock
            $lockedBooking = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($type === 'settlement' || $type === 'extension') {
                $alreadyPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange'])
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

            $cashierBranchId = $request->header('X-Branch-Id') ?? $user->affiliate_id ?? $booking->affiliate_id;

            $paymentTx = PaymentTransaction::create([
                'business_id' => $booking->business_id ?? $user->business_id,
                'branch_id' => $cashierBranchId ? (int)$cashierBranchId : null,
                'transaction_code' => $txCode,
                'receipt_number' => $receiptNumber,
                'idempotency_key' => $idempotencyKey,
                'booking_id' => $booking->id,
                'payment_method_id' => $paymentMethod->id,
                'processed_by' => $user->id,
                'type' => $type,
                'status' => 'completed',
                'direction' => $type === 'refund' ? 'outflow' : 'inflow',
                'amount' => $amountToPay,
                'cash_received' => $cashReceived,
                'cash_change' => $cashChange,
                'notes' => $request->notes ?? ($type === 'penalty' ? 'Pelunasan denda via Kasir POS' : ($type === 'extension' ? 'Pembayaran perpanjangan sewa via Kasir POS' : 'Pembayaran sewa via Kasir POS')),
                'transacted_at' => Carbon::now(),
            ]);

            // Update booking payment status
            if ($type === 'settlement' || $type === 'extension') {
                $newTotalPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange'])
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'void');
                    })
                    ->sum('amount');
                $newPaymentStatus = ($newTotalPaid >= (float)$lockedBooking->total_bill) ? 'paid' : ($newTotalPaid > 0 ? 'partial' : 'unpaid');
                $lockedBooking->update(['payment_status' => $newPaymentStatus]);
            } elseif ($type === 'penalty') {
                $totalPenaltyPaid = (float)$lockedBooking->payments()
                    ->where('type', 'penalty')
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'void');
                    })
                    ->sum('amount');
                $totalPenaltyDue = (float)($lockedBooking->returnInspection?->total_penalty ?? 0.0);
                $isRentalSettled = ((float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange'])
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
                    'booking' => $lockedBooking->fresh(['customer', 'payments.paymentMethod', 'items.asset.model']),
                    'amount_due' => $amountToPay,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'receipt_number' => $receiptNumber,
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

        foreach ($txSummaryList as $tx) {
            // Exclude void transactions from revenue calculations
            if (($tx->status ?? 'completed') === 'void') {
                continue;
            }

            $amount = (float)$tx->amount;
            $isRefund = ($tx->type === 'refund' || ($tx->direction ?? 'inflow') === 'outflow');

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

            $methodName = $tx->paymentMethod->name ?? 'Lainnya';
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

            $methodCode = strtolower($tx->paymentMethod->code ?? '');
            $isCash = $methodCode === 'cash' || str_contains(strtolower($methodName), 'tunai');

            $bId = $tx->branch_id ?? $tx->booking?->affiliate_id;
            if ($bId !== null) {
                if (!isset($branchStats[$bId])) {
                    $branchModel = \App\Models\Affiliate::find($bId);
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
        if ($targetBranchId !== null) {
            $activeShift = \App\Models\CashDrawerShift::where('branch_id', $targetBranchId)
                ->where('status', 'open')
                ->latest('opened_at')
                ->first();

            if ($activeShift) {
                $openingFloat = (float)$activeShift->opening_cash_float;
                $activeShiftData = [
                    'id' => $activeShift->id,
                    'shift_number' => $activeShift->shift_number,
                    'opened_at' => $activeShift->opened_at->toIso8601String(),
                    'opening_cash_float' => $openingFloat,
                    'status' => $activeShift->status,
                ];
            }
        }

        $totalCashRevenue = 0.00;
        foreach ($breakdown as $mName => $mStat) {
            if (str_contains(strtolower($mName), 'cash') || str_contains(strtolower($mName), 'tunai')) {
                $totalCashRevenue += (float)($mStat['total'] ?? 0);
            }
        }

        $expectedDrawerCash = max(0.00, $openingFloat + $totalCashRevenue - $cashDrawerExpenses);

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
                'type_label' => $tx->type === 'penalty'
                    ? 'Denda Keterlambatan (Late Return Penalty)'
                    : ($tx->type === 'extension'
                        ? 'Perpanjangan Sewa'
                        : ($tx->type === 'refund'
                            ? 'Pengembalian Dana / Deposit (Refund)'
                            : ($tx->type === 'dp' ? 'Uang Muka (DP)' : 'Sewa Kontrak Pokok'))),
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
                'cash_reconciliation' => [
                    'opening_cash_float' => (float)$openingFloat,
                    'net_cash_revenue' => (float)$totalCashRevenue,
                    'cash_drawer_expenses' => (float)$cashDrawerExpenses,
                    'expected_cash_in_drawer' => (float)$expectedDrawerCash,
                    'active_shift' => $activeShiftData,
                ],
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
            // Staff attempting void requires manager PIN
            if (empty($request->manager_pin)) {
                throw ValidationException::withMessages([
                    'manager_pin' => ['Pembatalan transaksi pembayaran kasir wajib disetujui menggunakan PIN Manager Cabang.'],
                ]);
            }

            $branchId = $transaction->branch_id ?? $user->affiliate_id;
            $throttleKey = 'manager_pin_verify:biz_' . ($businessId ?? 0) . ':user_' . $user->id;

            if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan PIN Manager salah. Silakan coba lagi dalam ' . ceil($seconds / 60) . ' menit.',
                    'code' => 'PIN_RATE_LIMITED',
                    'retry_after' => $seconds,
                ], 429);
            }

            $managers = User::where(function ($q) use ($branchId, $businessId) {
                if ($branchId) {
                    $q->where('affiliate_id', $branchId);
                }
                if ($businessId) {
                    $q->orWhereHas('businessMembers', function ($bm) use ($businessId) {
                        $bm->where('business_id', $businessId)->whereIn('role', ['owner', 'admin']);
                    });
                }
            })
            ->whereNotNull('manager_override_pin')
            ->get();

            $matchingManager = $managers->first(function ($mgr) use ($request) {
                return password_verify($request->manager_pin, $mgr->manager_override_pin);
            });

            if (!$matchingManager) {
                RateLimiter::hit($throttleKey, 900);
                $remaining = 5 - RateLimiter::attempts($throttleKey);
                throw ValidationException::withMessages([
                    'manager_pin' => ['PIN Manager Cabang tidak valid.' . ($remaining > 0 ? " Sisa percobaan: {$remaining} kali." : ' Akun Anda dikunci sementara.')],
                ]);
            }

            RateLimiter::clear($throttleKey);
            $voidedByUserId = $matchingManager->id;
        }

        return DB::transaction(function () use ($transaction, $request, $voidedByUserId, $user) {
            $transaction->status = 'void';
            $transaction->void_reason = $request->reason;
            $transaction->void_by = $voidedByUserId;
            $transaction->save();

            // Recalculate related booking payment status if linked
            if ($transaction->booking_id) {
                $booking = Booking::find($transaction->booking_id);
                if ($booking) {
                    $validPaid = (float)$booking->payments()
                        ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange'])
                        ->where(function ($q) {
                            $q->whereNull('status')->orWhere('status', '!=', 'void');
                        })
                        ->sum('amount');

                    $newPaymentStatus = ($validPaid >= (float)$booking->total_bill)
                        ? 'paid'
                        : ($validPaid > 0 ? 'partial' : 'unpaid');

                    $booking->update(['payment_status' => $newPaymentStatus]);
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
