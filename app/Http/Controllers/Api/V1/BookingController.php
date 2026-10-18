<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\RentalExtension;
use App\Services\AuditLogService;
use App\Services\BookingService;
use App\Services\ReturnService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $bookingService,
        protected ReturnService $returnService
    ) {}

    /**
     * List bookings filtered by status, date, or query.
     */
    public function index(Request $request): JsonResponse
    {
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

        $query = Booking::with([
            'customer',
            'items.asset.model',
            'payments.paymentMethod',
            'payments.processedBy',
            'affiliate',
            'creator',
        ]);

        $isAllBranches = $request->boolean('all_branches')
            || $request->get('branch_id') === 'all'
            || $request->get('branch_id') === '-1';

        $rawBranchId = $isAllBranches ? null : (
            $request->get('branch_id')
            ?? $request->get('affiliate_id')
            ?? $request->header('X-Branch-Id')
            ?? ($tenantContext->hasActiveBranch() ? $tenantContext->getBranchId() : null)
        );

        $branchId = ($rawBranchId !== null && $rawBranchId !== '' && $rawBranchId !== 'null')
            ? (int)$rawBranchId
            : null;

        $canAccessConsolidated = $isOwner || $isSuperAdmin || $isCentralBranchStaff;

        if (!$canAccessConsolidated) {
            if ($isAllBranches) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak. Anda tidak berwenang melihat transaksi sewa seluruh cabang.',
                ], 403);
            }
            $allowedBranches = $user ? $user->getBranchIdsForBusiness($businessId) : [];
            if (empty($allowedBranches) && $user && $user->affiliate_id) {
                $allowedBranches = [(int)$user->affiliate_id];
            }
            if ($branchId !== null && !in_array((int)$branchId, $allowedBranches, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak. Anda tidak berwenang melihat transaksi sewa cabang lain.',
                ], 403);
            }
            if ($branchId === null && !empty($allowedBranches)) {
                $branchId = $allowedBranches[0];
            }
            if ($branchId !== null) {
                $query->where('bookings.affiliate_id', (int)$branchId);
            }
        } else {
            if ($isAllBranches) {
                $query->withoutGlobalScope(\App\Scopes\TenantScope::class);
                if ($businessId) {
                    $query->where('bookings.business_id', $businessId);
                }
            } elseif ($branchId !== null) {
                // Verify the requested branch belongs to this business
                if ($businessId) {
                    $branchValid = \App\Models\Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('id', $branchId)
                        ->where('business_id', $businessId)
                        ->exists();

                    if (!$branchValid) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cabang tidak ditemukan atau tidak berada dalam bisnis Anda.',
                        ], 404);
                    }
                }

                $query->withoutGlobalScope(\App\Scopes\TenantScope::class);
                if ($businessId) {
                    $query->where('bookings.business_id', $businessId);
                }
                $query->where('bookings.affiliate_id', (int)$branchId);
            } else {
                // Owner / Consolidated user did not specify all_branches and did not specify a branch
                // Scope to user's assigned branch if available, or first active branch of business
                $fallbackBranch = $user && $user->affiliate_id ? (int)$user->affiliate_id : null;
                if (!$fallbackBranch && $businessId) {
                    $firstBranch = \App\Models\Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
                        ->where('business_id', $businessId)
                        ->where('is_active', true)
                        ->first();
                    $fallbackBranch = $firstBranch?->id;
                }

                if ($fallbackBranch) {
                    $query->withoutGlobalScope(\App\Scopes\TenantScope::class);
                    if ($businessId) {
                        $query->where('bookings.business_id', $businessId);
                    }
                    $query->where('bookings.affiliate_id', $fallbackBranch);
                }
            }
        }

        if ($request->has('status')) {
            if ($request->status === 'overdue') {
                $query->where('bookings.status', 'rented')
                      ->where('bookings.end_at', '<=', Carbon::now());
            } else {
                $query->where('bookings.status', $request->status);
            }
        }

        if ($request->has('payment_status')) {
            $query->where('bookings.payment_status', $request->payment_status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('bookings.booking_code', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        $bookings = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $bookings,
        ]);
    }

    /**
     * Create direct counter rental transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'affiliate_id' => 'nullable|integer|exists:affiliates,id',
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|string|max:30',
            'customer_email' => 'nullable|email',
            'customer_address' => 'nullable|string',
            'identity_type' => 'nullable|in:KTP,SIM,KK,PASSPORT,KARTU_PELAJAR',
            'identity_number' => 'nullable|string|max:100',
            'identity_photo' => 'nullable',
            'identity_photo_base64' => 'nullable|string',
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer|exists:assets,id',
            'duration_hours' => 'nullable|integer|min:1',
            'duration_unit' => 'nullable|string|in:hour,day,week,month',
            'duration_value' => 'nullable|integer|min:1',
            'start_at' => 'nullable|date',
            'deposit_fee' => 'nullable|numeric|min:0',
            'voucher_code' => 'nullable|string',
            'manual_discount_amount' => 'nullable|numeric|min:0',
            'manual_discount_reason' => 'nullable|string',
            'manager_pin' => 'nullable|string',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'collateral_type' => 'nullable|string|max:50',
            'collateral_storage_slot' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'unit_prices' => 'nullable|array',
            'unit_prices.*' => 'numeric|min:0',
        ]);

        $payload = $request->all();
        $headerBranchId = $request->header('X-Branch-Id') ? (int)$request->header('X-Branch-Id') : null;
        if (!empty($payload['affiliate_id']) && $headerBranchId && (int)$payload['affiliate_id'] !== $headerBranchId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'affiliate_id' => ['Cabang transaksi tidak sesuai dengan cabang aktif yang dipilih.'],
            ]);
        }
        if (empty($payload['affiliate_id']) && $headerBranchId) {
            $payload['affiliate_id'] = $headerBranchId;
        }

        $booking = $this->bookingService->createCounterRental($payload, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Transaksi sewa berhasil dibuat dan unit langsung aktif disewa.',
            'data' => $booking,
        ], 201);
    }

    /**
     * Get booking details by ID or code.
     */
    public function show(string $id): JsonResponse
    {
        $booking = Booking::with([
            'customer.identities',
            'items.asset.model',
            'payments.paymentMethod',
            'payments.processedBy',
            'voucher',
            'extensions.extender',
            'returnInspection.inspector',
            'creator',
            'affiliate'
        ])
        ->where(function ($q) use ($id) {
            $q->where('id', $id)->orWhere('booking_code', $id);
        })
        ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $booking,
        ]);
    }

    /**
     * Add rental hours extension.
     */
    public function extend(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'additional_hours' => 'required|integer|min:1|max:72',
            'additional_fee' => 'nullable|numeric|min:0',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $booking = Booking::with(['items.asset.model', 'affiliate'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($booking->status !== 'rented') {
                return response()->json([
                    'success' => false,
                    'message' => 'Hanya rental berstatus aktif (rented) yang dapat diperpanjang.',
                ], 422);
            }

            $additionalHours = (int)$request->additional_hours;
            $previousEndAt = Carbon::parse($booking->end_at);
            $newEndAt = (clone $previousEndAt)->addHours($additionalHours);

            // Collision check: verify asset is not booked for subsequent confirmed reservation
            $assetIds = $booking->items->pluck('asset_id')->toArray();
            $collision = Booking::where('id', '!=', $booking->id)
                ->whereIn('status', ['confirmed', 'rented'])
                ->whereHas('items', function ($iq) use ($assetIds) {
                    $iq->whereIn('asset_id', $assetIds);
                })
                ->where(function ($tq) use ($previousEndAt, $newEndAt) {
                    $tq->where('start_at', '<', $newEndAt)
                       ->where('end_at', '>', $previousEndAt);
                })
                ->first();

            if ($collision) {
                return response()->json([
                    'success' => false,
                    'message' => "Tidak dapat memperpanjang sewa. Perangkat telah dipesan untuk jadwal berikutnya (Booking: {$collision->booking_code}).",
                ], 422);
            }

            // Authoritative calculation from PricingTier
            $hourlyRate = 15000.00;
            $firstItem = $booking->items()->first();
            if ($firstItem && $firstItem->asset) {
                $tier = PricingTier::where('model_id', $firstItem->asset->model_id)->first();
                if ($tier && (float)$tier->extension_hourly_rate > 0) {
                    $hourlyRate = (float)$tier->extension_hourly_rate;
                }
            }
            $additionalFee = $request->filled('additional_fee')
                ? (float)$request->additional_fee
                : ($additionalHours * $hourlyRate);

            $extension = RentalExtension::create([
                'booking_id' => $booking->id,
                'extended_by' => $request->user()->id,
                'previous_end_at' => $previousEndAt,
                'new_end_at' => $newEndAt,
                'additional_hours' => $additionalHours,
                'additional_fee' => $additionalFee,
            ]);

            $newTotalBill = (float)$booking->total_bill + $additionalFee;
            $paymentTx = null;

            if ($request->filled('payment_method_id')) {
                $paymentMethod = PaymentMethod::findOrFail($request->payment_method_id);
                $isCash = strtoupper($paymentMethod->code) === 'CASH';

                if ($isCash) {
                    $cashReceived = (float)($request->cash_received ?? $additionalFee);
                    if ($cashReceived < $additionalFee) {
                        throw ValidationException::withMessages([
                            'cash_received' => [
                                'Jumlah uang tunai yang diterima (Rp ' . number_format($cashReceived, 0, ',', '.') . 
                                ') kurang dari biaya perpanjangan (Rp ' . number_format($additionalFee, 0, ',', '.') . ').'
                            ],
                        ]);
                    }
                    $cashChange = max(0, $cashReceived - $additionalFee);
                } else {
                    $cashReceived = $additionalFee;
                    $cashChange = 0.00;
                }

                $datePrefix = Carbon::now()->format('ymd');
                $txCode = sprintf('TX-EXT-%s-%04d', $datePrefix, PaymentTransaction::count() + 1);
                $receiptNumber = sprintf('REC-EXT-%s-%s-%04d', $booking->affiliate->code ?? 'PST', $datePrefix, $booking->id);

                $paymentTx = PaymentTransaction::create([
                    'business_id' => $booking->business_id ?? $request->user()->business_id,
                    'transaction_code' => $txCode,
                    'receipt_number' => $receiptNumber,
                    'booking_id' => $booking->id,
                    'payment_method_id' => $paymentMethod->id,
                    'processed_by' => $request->user()->id,
                    'type' => 'extension',
                    'amount' => $additionalFee,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'notes' => $request->notes ?? "Pembayaran perpanjangan sewa {$additionalHours} jam",
                    'transacted_at' => Carbon::now(),
                ]);
            }

            $alreadyPaid = (float)$booking->payments()->whereIn('type', ['dp', 'settlement', 'extension'])->sum('amount')
                + ($paymentTx ? (float)$paymentTx->amount : 0.0);
            $newPaymentStatus = ($alreadyPaid >= $newTotalBill) ? 'paid' : 'partial';

            $booking->update([
                'end_at' => $newEndAt,
                'duration_hours' => $booking->duration_hours + $additionalHours,
                'total_bill' => $newTotalBill,
                'payment_status' => $newPaymentStatus,
            ]);

            // Immutable Audit Log
            AuditLogService::log(
                $request->user(),
                'booking.extended',
                'RentalExtension',
                $extension->id,
                [
                    'booking_code' => $booking->booking_code,
                    'additional_hours' => $additionalHours,
                    'additional_fee' => $additionalFee,
                    'new_end_at' => $newEndAt->toDateTimeString(),
                    'payment_recorded' => $paymentTx !== null,
                ],
                [
                    'previous_end_at' => $previousEndAt->toDateTimeString(),
                ],
                $request->ip(),
                $booking->affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => "Sewa berhasil diperpanjang {$additionalHours} jam hingga {$newEndAt->format('d M Y, H:i')}.",
                'data' => [
                    'booking' => $booking->fresh(['items.asset.model', 'extensions', 'payments.paymentMethod']),
                    'extension' => $extension,
                    'payment' => $paymentTx,
                    'receipt_number' => $paymentTx?->receipt_number,
                ],
            ]);
        });
    }

    /**
     * Process return inspection, enforce iCloud check, calculate penalty.
     */
    public function processReturn(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'returned_at' => 'nullable|date',
            'condition_grade' => 'required|in:A,B,C',
            'battery_health_final' => 'nullable|integer|min:0|max:100',
            'checklist_results' => 'required|array',
            'checklist_results.icloud_removed' => 'nullable|boolean',
            'accessories_returned' => 'nullable|array',
            'damage_fee' => 'nullable|numeric|min:0',
            'penalty_payment_method_id' => 'nullable|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'staff_notes' => 'nullable|string',
        ]);

        $booking = Booking::with('items.asset.model')->findOrFail($id);

        $inspection = $this->returnService->processReturn($booking, $request->all(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Pengembalian unit berhasil diproses dan unit dikembalikan ke status siap/perbaikan.',
            'data' => $inspection,
        ]);
    }
}
