<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($booking, $paymentMethod, $isCash, $type, $request, $user) {
            // Re-fetch booking with row-level pessimistic lock
            $lockedBooking = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($type === 'settlement') {
                $alreadyPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension'])
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
                $penaltyPaid = (float)$lockedBooking->payments()->where('type', 'penalty')->sum('amount');
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
            $txCode = sprintf('TX-%s-%04d', $datePrefix, PaymentTransaction::count() + 1);
            $receiptNumber = sprintf('REC-%s-%s-%04d', $booking->affiliate->code ?? 'PST', $datePrefix, $booking->id);

            $paymentTx = PaymentTransaction::create([
                'business_id' => $booking->business_id ?? $user->business_id,
                'transaction_code' => $txCode,
                'receipt_number' => $receiptNumber,
                'booking_id' => $booking->id,
                'payment_method_id' => $paymentMethod->id,
                'processed_by' => $user->id,
                'type' => $type,
                'amount' => $amountToPay,
                'cash_received' => $cashReceived,
                'cash_change' => $cashChange,
                'notes' => $request->notes ?? ($type === 'penalty' ? 'Pelunasan denda via Kasir POS' : 'Pembayaran sewa via Kasir POS'),
                'transacted_at' => Carbon::now(),
            ]);

            // Update booking payment status
            if ($type === 'settlement') {
                $newTotalPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension'])
                    ->sum('amount');
                $newPaymentStatus = ($newTotalPaid >= (float)$lockedBooking->total_bill) ? 'paid' : 'partial';
                $lockedBooking->update(['payment_status' => $newPaymentStatus]);
            } elseif ($type === 'penalty') {
                $totalPenaltyPaid = (float)$lockedBooking->payments()->where('type', 'penalty')->sum('amount');
                $totalPenaltyDue = (float)($lockedBooking->returnInspection?->total_penalty ?? 0.0);
                $isRentalSettled = ((float)$lockedBooking->payments()->whereIn('type', ['dp', 'settlement', 'extension'])->sum('amount')) >= (float)$lockedBooking->total_bill;
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

        if ($startDate && $endDate) {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = Carbon::parse($endDate)->endOfDay();
            $dateLabel = $startDate . ' - ' . $endDate;
        } elseif ($dateParam) {
            $start = Carbon::parse($dateParam)->startOfDay();
            $end = Carbon::parse($dateParam)->endOfDay();
            $dateLabel = $dateParam;
        } else {
            $today = Carbon::today()->toDateString();
            $start = Carbon::today()->startOfDay();
            $end = Carbon::today()->endOfDay();
            $dateLabel = $today;
        }

        $user = $request->user();
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();
        $isSuperAdmin = $user && $user->isSuperAdmin();

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

        $query = PaymentTransaction::with([
            'paymentMethod',
            'booking' => fn($q) => $q->withoutGlobalScope(\App\Scopes\TenantScope::class),
            'booking.affiliate',
            'booking.customer',
            'booking.items.asset.model',
            'processedBy'
        ])
        ->whereBetween('transacted_at', [$start, $end]);

        if ($businessId) {
            $query->where(function ($q) use ($businessId) {
                $q->where('payment_transactions.business_id', $businessId)
                  ->orWhereHas('booking', function ($bq) use ($businessId) {
                      $bq->where('business_id', $businessId);
                  });
            });
        }

        if ($targetBranchId !== null) {
            $query->whereHas('booking', function ($bq) use ($targetBranchId) {
                $bq->where('affiliate_id', $targetBranchId);
            });
        }

        $transactions = $query->latest('transacted_at')->get();

        $breakdown = [];
        $totalRevenue = 0.00;
        $totalTransactions = $transactions->count();

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
        ];

        foreach ($transactions as $tx) {
            $amount = (float)$tx->amount;
            $totalRevenue += $amount;

            $txType = $tx->type ?? 'settlement';
            if ($txType === 'penalty') {
                $typeBreakdown['penalty']['count']++;
                $typeBreakdown['penalty']['total'] += $amount;
            } elseif ($txType === 'extension') {
                $typeBreakdown['extension']['count']++;
                $typeBreakdown['extension']['total'] += $amount;
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
            $breakdown[$methodName]['total'] += $amount;

            $methodCode = strtolower($tx->paymentMethod->code ?? '');
            $isCash = $methodCode === 'cash' || str_contains(strtolower($methodName), 'tunai');

            $bId = $tx->booking?->affiliate_id;
            if ($bId !== null) {
                if (!isset($branchStats[$bId])) {
                    $bName = $tx->booking?->affiliate?->name ?? ('Cabang #' . $bId);
                    $bCode = $tx->booking?->affiliate?->code ?? 'CBG';
                    $isCentral = (bool)($tx->booking?->affiliate?->is_central ?? false);
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
                $branchStats[$bId]['total_revenue'] += $amount;
                if ($isCash) {
                    $branchStats[$bId]['cash_revenue'] += $amount;
                } else {
                    $branchStats[$bId]['non_cash_revenue'] += $amount;
                }
            }
        }

        $branchBreakdownList = array_values($branchStats);
        if ($targetBranchId !== null) {
            $branchBreakdownList = array_values(array_filter($branchBreakdownList, fn($b) => $b['branch_id'] === $targetBranchId));
        }

        $limit = max(10, (int)$request->get('limit', $request->get('per_page', 50)));
        $recentTransactions = $transactions->take($limit)->map(function ($tx) {
            return [
                'id' => $tx->id,
                'transaction_code' => $tx->transaction_code,
                'receipt_number' => $tx->receipt_number,
                'booking_id' => $tx->booking_id,
                'booking_code' => $tx->booking?->booking_code,
                'customer_name' => $tx->booking?->customer?->name ?? 'Pelanggan',
                'customer_phone' => $tx->booking?->customer?->phone ?? '',
                'branch_id' => $tx->booking?->affiliate_id,
                'branch_name' => $tx->booking?->affiliate?->name ?? ($tx->booking?->affiliate_id ? 'Cabang #' . $tx->booking->affiliate_id : 'Tidak Teridentifikasi'),
                'is_central' => (bool)($tx->booking?->affiliate?->is_central ?? false),
                'cashier_name' => $tx->processedBy?->name ?? 'Kasir',
                'amount' => (float)$tx->amount,
                'payment_method' => $tx->paymentMethod?->name ?? 'Tunai',
                'type' => $tx->type,
                'type_label' => $tx->type === 'penalty'
                    ? 'Denda Keterlambatan (Late Return Penalty)'
                    : ($tx->type === 'extension' ? 'Perpanjangan Sewa' : ($tx->type === 'dp' ? 'Uang Muka (DP)' : 'Sewa Kontrak Pokok')),
                'cash_received' => (float)$tx->cash_received,
                'cash_change' => (float)$tx->cash_change,
                'notes' => $tx->notes,
                'transacted_at' => $tx->transacted_at?->toISOString() ?? (string)$tx->transacted_at,
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
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_revenue' => (float)$totalRevenue,
                'total_transactions' => (int)$totalTransactions,
                'breakdown_by_type' => (object)$typeBreakdown,
                'breakdown_by_method' => (object)$breakdown,
                'breakdown_by_branch' => $branchBreakdownList,
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }
}
