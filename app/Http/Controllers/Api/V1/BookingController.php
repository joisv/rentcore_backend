<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\RentalExtension;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BookingAvailabilityService;
use App\Services\BookingService;
use App\Services\ExchangeService;
use App\Services\ManagerPinVerificationService;
use App\Services\PricingService;
use App\Services\ReturnService;
use App\Services\ShiftEnforcementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $bookingService,
        protected ReturnService $returnService,
        protected ExchangeService $exchangeService,
        protected PricingService $pricingService,
        protected BookingAvailabilityService $availabilityService
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
            'returnInspection',
            'assetModel.category',
            'assetModel.pricingTiers' => function ($pq) {
                $pq->where('is_active', true)->orderBy('duration_hours', 'asc');
            },
            'assetUnit.model',
            'items.asset.model.category',
            'items.asset.model.pricingTiers' => function ($pq) {
                $pq->where('is_active', true)->orderBy('duration_hours', 'asc');
            },
            'items.assetModel.category',
            'payments.paymentMethod',
            'payments.processedBy',
            'exchanges.oldAsset.model',
            'exchanges.newAsset.model',
            'exchanges.handler',
            'exchanges.manager',
            'exchanges.payment.paymentMethod',
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

        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->where('bookings.status', 'rented')
                      ->where('bookings.end_at', '<=', Carbon::now());
            } elseif ($request->status === 'booked') {
                $query->whereIn('bookings.status', ['booked', 'reserved', 'confirmed']);
            } elseif ($request->status === 'active') {
                $query->whereIn('bookings.status', ['rented', 'active']);
            } else {
                $query->where('bookings.status', $request->status);
            }
        }

        if ($request->has('payment_status')) {
            $query->where('bookings.payment_status', $request->payment_status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('bookings.booking_code', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  })
                  ->orWhereHas('assetModel', function ($mq) use ($search) {
                      $mq->where('name', 'like', "%{$search}%")
                         ->orWhere('brand', 'like', "%{$search}%");
                  })
                  ->orWhereHas('items.asset', function ($aq) use ($search) {
                      $aq->where('asset_code', 'like', "%{$search}%")
                         ->orWhere('serial_number', 'like', "%{$search}%")
                         ->orWhereHas('model', function ($mq) use ($search) {
                             $mq->where('name', 'like', "%{$search}%")
                                ->orWhere('brand', 'like', "%{$search}%");
                         });
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
     * Create direct counter rental transaction or scheduled reservation (`booked`).
     */
    public function store(Request $request): JsonResponse
    {
        $isScheduled = $request->has('is_scheduled')
            ? $request->boolean('is_scheduled')
            : ($request->input('booking_mode') === 'scheduled' || $request->input('status') === 'booked');

        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? $request->user()?->business_id;

        $isAllBranches = $request->boolean('all_branches')
            || in_array((string) $request->input('branch_id'), ['all', '-1'], true)
            || in_array((string) $request->input('affiliate_id'), ['all', '-1'], true);

        if ($isAllBranches) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang aktif wajib dipilih (mode Semua Cabang tidak dapat digunakan untuk membuat booking).'],
            ]);
        }

        if ($request->filled('branch_id') && $request->filled('affiliate_id') && (int) $request->input('branch_id') !== (int) $request->input('affiliate_id')) {
            throw ValidationException::withMessages([
                'branch_id' => ['Parameter branch_id dan affiliate_id tidak sesuai.'],
            ]);
        }

        if (!$request->filled('branch_id')) {
            $fallbackBranchId = $request->input('affiliate_id')
                ?? $request->header('X-Branch-Id')
                ?? $tenantContext->getBranchId()
                ?? $request->user()?->affiliate_id;
            if ($fallbackBranchId !== null && $fallbackBranchId !== '' && $fallbackBranchId !== 'null') {
                $request->merge(['branch_id' => $fallbackBranchId]);
            }
        }

        $request->validate([
            'is_scheduled' => 'nullable|boolean',
            'booking_mode' => 'nullable|in:direct,scheduled',
            'status' => 'nullable|in:rented,active,booked',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'branch_id' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::exists('affiliates', 'id')->where(function ($q) use ($businessId) {
                    $q->where('is_active', true);
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'affiliate_id' => 'nullable|integer|exists:affiliates,id',
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|string|max:30',
            'customer_email' => 'nullable|email',
            'customer_address' => 'nullable|string',
            'identity_type' => 'nullable|in:KTP,SIM,KK,PASSPORT,KARTU_PELAJAR',
            'identity_number' => 'nullable|string|max:100',
            'identity_photo' => 'nullable',
            'identity_photo_base64' => 'nullable|string',
            'asset_model_id' => 'nullable|integer|exists:asset_models,id',
            'device_model_id' => 'nullable|integer|exists:asset_models,id',
            'asset_unit_id' => 'nullable|integer|exists:assets,id',
            'asset_ids' => ($isScheduled ? 'nullable' : 'required') . '|array' . ($isScheduled ? '' : '|min:1'),
            'asset_ids.*' => 'integer|exists:assets,id',
            'addon_asset_ids' => 'nullable|array',
            'addon_asset_ids.*' => 'integer|exists:assets,id',
            'duration_hours' => 'nullable|integer|min:1',
            'duration_unit' => 'nullable|string|in:hour,day,week,month',
            'duration_value' => 'nullable|integer|min:1',
            'start_at' => $isScheduled
                ? 'required|date|after_or_equal:' . now()->subMinutes(5)->toDateTimeString()
                : 'nullable|date',
            'deposit_fee' => 'nullable|numeric|min:0',
            'voucher_code' => 'nullable|string',
            'manual_discount_amount' => 'nullable|numeric|min:0',
            'manual_discount_reason' => 'nullable|string',
            'manager_pin' => 'nullable|string',
            'payment_method_id' => ($isScheduled ? 'nullable' : 'required') . '|integer|exists:payment_methods,id',
            'paid_amount' => 'nullable|numeric|min:0',
            'dp_amount' => 'nullable|numeric|min:0',
            'cash_received' => 'nullable|numeric|min:0',
            'collateral_type' => 'nullable|string|max:50',
            'collateral_storage_slot' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'external_reference' => 'nullable|string|max:100',
            'unit_prices' => 'nullable|array',
            'unit_prices.*' => 'numeric|min:0',
            'idempotency_key' => 'nullable|string|max:64',
        ], [
            'branch_id.required' => 'Cabang aktif wajib dipilih untuk membuat booking.',
            'branch_id.exists' => 'Cabang tidak valid atau tidak dimiliki oleh bisnis ini.',
            'start_at.required' => 'Waktu pengambilan wajib diisi untuk booking terjadwal.',
            'start_at.after_or_equal' => 'Pilih waktu dan tanggal di masa mendatang untuk booking terjadwal.',
        ]);

        $payload = $request->all();
        $payload['branch_id'] = (int) $request->input('branch_id');
        $payload['affiliate_id'] = (int) $request->input('branch_id');
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        if (!empty($idempotencyKey)) {
            $payload['idempotency_key'] = (string)$idempotencyKey;
        }

        $findExistingByIdempotency = function () use ($idempotencyKey, $businessId): ?Booking {
            if (empty($idempotencyKey)) {
                return null;
            }
            $existingBooking = Booking::withoutGlobalScopes()
                ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (!$existingBooking) {
                $existingTx = PaymentTransaction::withoutGlobalScopes()
                    ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                $existingBooking = $existingTx?->booking_id
                    ? Booking::withoutGlobalScopes()->find($existingTx->booking_id)
                    : null;
            }

            return $existingBooking?->load([
                'items.asset.model',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'voucher',
                'affiliate',
            ]);
        };

        if ($existingBooking = $findExistingByIdempotency()) {
            return response()->json([
                'success' => true,
                'message' => 'Transaksi booking telah diproses sebelumnya (Idempotent).',
                'is_idempotent' => true,
                'data' => $existingBooking,
            ], 200);
        }

        $headerBranchId = $request->header('X-Branch-Id') ? (int)$request->header('X-Branch-Id') : null;
        if (!empty($payload['affiliate_id']) && $headerBranchId && (int)$payload['affiliate_id'] !== $headerBranchId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'affiliate_id' => ['Cabang transaksi tidak sesuai dengan cabang aktif yang dipilih.'],
            ]);
        }
        if (empty($payload['affiliate_id']) && $headerBranchId) {
            $payload['affiliate_id'] = $headerBranchId;
        }

        try {
            if ($isScheduled) {
                $booking = $this->bookingService->createScheduledBooking($payload, $request->user());
                return response()->json([
                    'success' => true,
                    'message' => 'Booking terjadwal berhasil dibuat.',
                    'data' => $booking,
                ], 201);
            }

            // Strict pre-checkout active shift enforcement
            $targetBranchId = (int)($payload['affiliate_id'] ?? $headerBranchId ?? $request->user()->affiliate_id);
            $activeShift = null;
            if (!empty($payload['shift_id'])) {
                $activeShift = \App\Models\CashDrawerShift::where('id', $payload['shift_id'])
                    ->where('branch_id', $targetBranchId)
                    ->where('status', 'open')
                    ->first();
            }
            $activeShift = $activeShift ?? ShiftEnforcementService::requireActiveShift(
                $targetBranchId,
                $request->user(),
                true,
                'memproses transaksi'
            );

            $booking = $this->bookingService->createCounterRental($payload, $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Transaksi sewa berhasil dibuat dan unit langsung aktif disewa.',
                'data' => $booking,
            ], 201);
        } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
            if ($existingBooking = $findExistingByIdempotency()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Transaksi booking telah diproses sebelumnya (Idempotent).',
                    'is_idempotent' => true,
                    'data' => $existingBooking,
                ], 200);
            }
            throw $e;
        }
    }

    /**
     * Get booking details by ID or code.
     */
    public function show(string $id): JsonResponse
    {
        $booking = Booking::with([
            'customer.identities',
            'assetModel.category',
            'assetModel.pricingTiers' => function ($pq) {
                $pq->where('is_active', true)->orderBy('duration_hours', 'asc');
            },
            'assetUnit.model',
            'items.asset.model.category',
            'items.asset.model.pricingTiers' => function ($pq) {
                $pq->where('is_active', true)->orderBy('duration_hours', 'asc');
            },
            'items.assetModel.category',
            'payments.paymentMethod',
            'payments.processedBy',
            'voucher',
            'extensions.extender',
            'returnInspection.inspector',
            'exchanges.oldAsset.model',
            'exchanges.newAsset.model',
            'exchanges.handler',
            'exchanges.manager',
            'exchanges.payment.paymentMethod',
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
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $idempotencyKey = $request->idempotency_key ?? $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $existingTx = PaymentTransaction::where('booking_id', $id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingTx) {
                $booking = Booking::with(['items.asset.model', 'extensions', 'payments.paymentMethod'])->findOrFail($id);
                return response()->json([
                    'success' => true,
                    'message' => 'Permintaan perpanjangan sewa telah berhasil diproses sebelumnya (Idempotent).',
                    'data' => [
                        'booking' => $booking,
                        'extension' => $booking->extensions()->latest()->first(),
                        'payment' => $existingTx,
                        'is_idempotent' => true,
                    ],
                ], 200);
            }
        }

        return DB::transaction(function () use ($request, $id, $idempotencyKey) {
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

            // Collision check: verify active asset is not booked for subsequent confirmed reservation
            $activeItems = $booking->activeItems->isNotEmpty() ? $booking->activeItems : $booking->items;
            $assetIds = $activeItems->pluck('asset_id')->toArray();
            $collision = Booking::where('id', '!=', $booking->id)
                ->whereIn('status', ['booked', 'reserved', 'confirmed', 'rented', 'active'])
                ->whereHas('items', function ($iq) use ($assetIds) {
                    $iq->whereIn('asset_id', $assetIds)
                       ->where(function ($sq) {
                           $sq->whereNull('status')->orWhere('status', 'active');
                       });
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

            // Authoritative server-side price calculation from PricingTier
            $firstItem = $booking->active_item ?? $booking->items()->first();
            $modelId = $firstItem?->asset?->model_id;
            $additionalFee = 0.0;

            if ($modelId) {
                // Check if an exact active tier exists matching this duration
                $exactTier = PricingTier::where('model_id', $modelId)
                    ->where('is_active', true)
                    ->where('duration_hours', $additionalHours)
                    ->first();

                if ($exactTier) {
                    $additionalFee = (float)$exactTier->price;
                } else {
                    // Fallback to model's configured extension hourly rate
                    $anyTier = PricingTier::where('model_id', $modelId)
                        ->where('is_active', true)
                        ->where('extension_hourly_rate', '>', 0)
                        ->first();
                    $hourlyRate = $anyTier ? (float)$anyTier->extension_hourly_rate : 15000.00;
                    $additionalFee = $additionalHours * $hourlyRate;
                }
            } else {
                $additionalFee = $additionalHours * 15000.00;
            }

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
                $isCash = strtoupper($paymentMethod->code) === 'CASH' || stripos($paymentMethod->name, 'tunai') !== false;

                $branchId = (int)($booking->affiliate_id ?? $request->header('X-Branch-Id') ?? $request->user()->affiliate_id);

                // Enforce business & branch payment method availability
                $business = Business::find($booking->business_id);
                if ($business && !$business->isPaymentMethodEnabled($paymentMethod, $branchId)) {
                    throw ValidationException::withMessages([
                        'payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
                    ]);
                }

                $activeShift = ShiftEnforcementService::requireActiveShift(
                    $branchId,
                    $request->user(),
                    $isCash,
                    'memproses pembayaran perpanjangan sewa di kasir'
                );

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
                $countToday = PaymentTransaction::withoutGlobalScopes()
                    ->where('business_id', $booking->business_id ?? $request->user()->business_id)
                    ->whereDate('transacted_at', Carbon::today())
                    ->count() + 1;
                $randomSuffix = strtoupper(\Illuminate\Support\Str::random(3));
                $txCode = sprintf('TX-EXT-%s-%04d-%s', $datePrefix, $countToday, $randomSuffix);
                $receiptNumber = sprintf('REC-EXT-%s-%s-%s', $booking->affiliate->code ?? 'PST', $datePrefix, strtoupper(\Illuminate\Support\Str::random(4)));

                try {
                    $paymentTx = PaymentTransaction::create([
                        'business_id' => $booking->business_id ?? $request->user()->business_id,
                        'branch_id' => $booking->affiliate_id,
                        'shift_id' => $activeShift->id,
                        'status' => 'completed',
                        'transaction_code' => $txCode,
                        'receipt_number' => $receiptNumber,
                        'idempotency_key' => $idempotencyKey,
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
                } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
                    if ($idempotencyKey) {
                        $paymentTx = PaymentTransaction::where('idempotency_key', $idempotencyKey)->first();
                    }
                    if (!$paymentTx) {
                        throw $e;
                    }
                }
            }

            $booking->end_at = $newEndAt;
            $booking->duration_hours = $booking->duration_hours + $additionalHours;
            $booking->total_bill = $newTotalBill;
            $booking->recalculateFinancials();

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
     * Adjust rental duration (increase or decrease) for primary device or specific add-on.
     */
    public function adjustDuration(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'booking_item_id' => 'nullable|integer|exists:booking_items,id',
            'hours_delta' => 'required|integer|min:1|max:168',
            'direction' => 'required|string|in:increase,decrease',
            'manager_pin' => 'nullable|string',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:255',
            'current_end_at' => 'nullable|date',
            'additional_fee' => 'nullable|numeric|min:0',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $idempotencyKey = $validated['idempotency_key'] ?? $request->header('X-Idempotency-Key');
        if (!empty($idempotencyKey)) {
            $existingTx = PaymentTransaction::where('booking_id', $id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingTx) {
                $existingBooking = Booking::with(['items.asset.model.category', 'extensions', 'payments.paymentMethod'])->findOrFail($id);
                return response()->json([
                    'success' => true,
                    'message' => 'Penyesuaian durasi sewa telah diproses sebelumnya (Idempotent).',
                    'data' => [
                        'booking' => $existingBooking,
                        'adjustment' => $existingBooking->extensions()->latest('id')->first(),
                        'payment' => $existingTx,
                        'fee_delta' => (float)$existingTx->amount,
                        'new_end_at' => Carbon::parse($existingBooking->end_at)->toIso8601String(),
                        'is_idempotent' => true,
                    ],
                ]);
            }
        }

        return DB::transaction(function () use ($request, $validated, $id, $idempotencyKey) {
            $user = $request->user();
            $tenantContext = app(\App\Tenancy\TenantContext::class);
            $businessId = $tenantContext->getBusinessId() ?? $user->current_business_id ?? $user->business_id;

            $booking = Booking::with(['items.asset.model.category', 'affiliate', 'payments'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($businessId && $booking->business_id !== (int)$businessId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki wewenang untuk mengubah durasi sewa pada bisnis ini.',
                ], 403);
            }

            // Guardrail 1: Booking Status Check
            if (!in_array($booking->status, ['rented', 'confirmed'])) {
                return response()->json([
                    'success' => false,
                    'message' => "Penyesuaian durasi tidak diizinkan untuk rental berstatus '{$booking->status}'.",
                ], 422);
            }

            // Guardrail 2: Overdue Check
            if ($booking->status === 'rented' && Carbon::parse($booking->end_at)->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rental yang sudah melewati batas waktu (overdue) tidak dapat dikurangi atau diubah durasinya melalui menu ini.',
                ], 422);
            }

            $direction = $validated['direction'];
            $hoursDelta = (int)$validated['hours_delta'];
            $signedDelta = ($direction === 'increase') ? $hoursDelta : -$hoursDelta;
            $previousEndAt = Carbon::parse($booking->end_at);
            $newEndAt = (clone $previousEndAt);

            // Optimistic Concurrency Check
            if (!empty($validated['current_end_at'])) {
                $clientEndAt = Carbon::parse($validated['current_end_at']);
                if ($previousEndAt->ne($clientEndAt)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data rental telah diperbarui oleh staf lain. Silakan muat ulang halaman sebelum mencoba kembali.',
                    ], 409);
                }
            }

            // Resolve target item
            $targetItemId = $validated['booking_item_id'] ?? null;
            $targetItem = null;
            $isTargetAddon = false;

            if ($targetItemId) {
                $targetItem = $booking->items->where('id', $targetItemId)->first();
                if (!$targetItem) {
                    throw ValidationException::withMessages([
                        'booking_item_id' => ['Item sewa tidak ditemukan dalam pesanan ini.'],
                    ]);
                }
                $isTargetAddon = $targetItem->item_type === 'addon';
            } else {
                $targetItem = $booking->active_item ?? $booking->items->where('item_type', 'primary')->first() ?? $booking->items->first();
                $isTargetAddon = false;
            }

            if (!$targetItem || !$targetItem->asset || !$targetItem->asset->model) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data perangkat atau model unit tidak valid.',
                ], 422);
            }

            $targetModel = $targetItem->asset->model;
            $managerApprovedBy = null;
            $feeDelta = 0.00;

            if ($isTargetAddon) {
                // --- TARGET: INDIVIDUAL ADD-ON ---
                $currentAddonHours = (int)($targetItem->duration_value ?? $booking->duration_hours);
                $newAddonHours = $currentAddonHours + $signedDelta;

                if ($newAddonHours < 1) {
                    throw ValidationException::withMessages([
                        'hours_delta' => ["Durasi add-on tidak boleh kurang dari 1 jam (Durasi saat ini: {$currentAddonHours} jam)."],
                    ]);
                }

                $newEndAt = $previousEndAt;
                if ($direction === 'increase') {
                    $potentialNewEndAt = Carbon::parse($booking->start_at)->addHours($newAddonHours);
                    if ($potentialNewEndAt->gt($previousEndAt)) {
                        // Forward Collision Check for this specific add-on asset
                        $collision = Booking::where('id', '!=', $booking->id)
                            ->whereIn('status', ['booked', 'reserved', 'confirmed', 'rented', 'active'])
                            ->whereHas('items', function ($iq) use ($targetItem) {
                                $iq->where('asset_id', $targetItem->asset_id)
                                   ->where(function ($sq) {
                                       $sq->whereNull('status')->orWhere('status', 'active');
                                   });
                            })
                            ->where(function ($tq) use ($previousEndAt, $potentialNewEndAt) {
                                $tq->where('start_at', '<', $potentialNewEndAt)
                                   ->where('end_at', '>', $previousEndAt);
                            })
                            ->first();

                        if ($collision) {
                            return response()->json([
                                'success' => false,
                                'message' => "Tidak dapat memperpanjang add-on. Aksesoris {$targetModel->name} telah dipesan untuk jadwal berikutnya (Booking: {$collision->booking_code}).",
                            ], 422);
                        }

                        $newEndAt = $potentialNewEndAt;
                        $booking->duration_hours = $newAddonHours;
                        $booking->end_at = $newEndAt;
                    }
                }

                $oldPrice = (float)$targetItem->unit_price;
                $serverPrice = $this->pricingService->calculateItemPriceForDuration($targetModel, $newAddonHours);
                $serverDelta = $serverPrice - $oldPrice;

                if ($direction === 'increase' && $request->filled('additional_fee')) {
                    $feeDelta = (float)$request->additional_fee;
                    if (abs($feeDelta - $serverDelta) > 0.01) {
                        $matchingManager = ManagerPinVerificationService::verify(
                            $user,
                            $validated['manager_pin'] ?? null,
                            $businessId ? (int)$businessId : null,
                            (int)$booking->affiliate_id,
                            'Override nominal biaya penyesuaian durasi memerlukan otorisasi PIN Manager.',
                            $request->ip(),
                            'DurationAdjustment'
                        );
                        $managerApprovedBy = $matchingManager->id;
                    }
                    $newPrice = $oldPrice + $feeDelta;
                } else {
                    $newPrice = $serverPrice;
                    $feeDelta = $serverDelta;
                }

                $targetItem->update([
                    'duration_value' => $newAddonHours,
                    'unit_price' => $newPrice,
                ]);

                // Recalculate addon_subtotal
                $newAddonSubtotal = (float)$booking->items()->where('item_type', 'addon')->sum('unit_price');
                $booking->addon_subtotal = $newAddonSubtotal;
            } else {
                // --- TARGET: PRIMARY DEVICE (SHIFTS BOOKING RETURN TIME) ---
                $currentBookingHours = (int)$booking->duration_hours;
                $newBookingHours = $currentBookingHours + $signedDelta;

                if ($newBookingHours < 1) {
                    throw ValidationException::withMessages([
                        'hours_delta' => ["Durasi rental tidak boleh kurang dari 1 jam (Durasi saat ini: {$currentBookingHours} jam)."],
                    ]);
                }

                if ($direction === 'decrease') {
                    $newEndAt = (clone $previousEndAt)->subHours($hoursDelta);
                    if ($booking->status === 'rented' && $newEndAt->lt(Carbon::now())) {
                        throw ValidationException::withMessages([
                            'hours_delta' => [
                                "Pengurangan durasi menghasilkan batas waktu di masa lalu ({$newEndAt->format('d M Y, H:i')}). " .
                                "Untuk pengembalian unit sebelum jadwal, silakan gunakan menu Pengembalian Unit."
                            ],
                        ]);
                    }
                } else {
                    $newEndAt = (clone $previousEndAt)->addHours($hoursDelta);

                    // Forward Collision Check
                    $activeItems = $booking->activeItems->isNotEmpty() ? $booking->activeItems : $booking->items;
                    $assetIds = $activeItems->pluck('asset_id')->toArray();
                    $collision = Booking::where('id', '!=', $booking->id)
                        ->whereIn('status', ['booked', 'reserved', 'confirmed', 'rented', 'active'])
                        ->whereHas('items', function ($iq) use ($assetIds) {
                            $iq->whereIn('asset_id', $assetIds)
                               ->where(function ($sq) {
                                   $sq->whereNull('status')->orWhere('status', 'active');
                               });
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
                }

                $oldPrimarySubtotal = (float)$booking->primary_subtotal;
                $serverPrimarySubtotal = $this->pricingService->calculateItemPriceForDuration($targetModel, $newBookingHours);
                $serverPrimaryDelta = $serverPrimarySubtotal - $oldPrimarySubtotal;

                if ($direction === 'increase' && $request->filled('additional_fee')) {
                    $feeDelta = (float)$request->additional_fee;
                    if (abs($feeDelta - $serverPrimaryDelta) > 0.01) {
                        $matchingManager = ManagerPinVerificationService::verify(
                            $user,
                            $validated['manager_pin'] ?? null,
                            $businessId ? (int)$businessId : null,
                            (int)$booking->affiliate_id,
                            'Override nominal biaya perpanjangan durasi memerlukan otorisasi PIN Manager.',
                            $request->ip(),
                            'DurationAdjustment'
                        );
                        $managerApprovedBy = $matchingManager->id;
                    }
                    $newPrimarySubtotal = $oldPrimarySubtotal + $feeDelta;
                } else {
                    $newPrimarySubtotal = $serverPrimarySubtotal;
                }

                $targetItem->update([
                    'duration_value' => $newBookingHours,
                    'unit_price' => $newPrimarySubtotal,
                ]);

                $booking->primary_subtotal = $newPrimarySubtotal;
                $booking->duration_hours = $newBookingHours;
                $booking->end_at = $newEndAt;

                // Cap attached add-ons if primary device was reduced below their duration
                if ($direction === 'decrease') {
                    foreach ($booking->items->where('item_type', 'addon') as $addonItem) {
                        if ($addonItem->duration_value > $newBookingHours) {
                            $newAddonPrice = $this->pricingService->calculateItemPriceForDuration($addonItem->asset->model, $newBookingHours);
                            $addonItem->update([
                                'duration_value' => $newBookingHours,
                                'unit_price' => $newAddonPrice,
                            ]);
                        }
                    }
                    $booking->addon_subtotal = (float)$booking->items()->where('item_type', 'addon')->sum('unit_price');
                }

                $newTotalSubtotal = (float)$booking->primary_subtotal + (float)$booking->addon_subtotal;
                if (!($direction === 'increase' && $request->filled('additional_fee'))) {
                    $feeDelta = $newTotalSubtotal - (float)$booking->subtotal_amount;
                }
            }

            // Recalculate Subtotal & Total Bill
            $previousTotalBill = (float)$booking->total_bill;
            $newSubtotal = (float)$booking->primary_subtotal + (float)$booking->addon_subtotal;
            $newTotalBill = max(0, $newSubtotal - (float)$booking->discount_amount + (float)$booking->deposit_fee);
            $booking->subtotal_amount = $newSubtotal;
            $booking->total_bill = $newTotalBill;

            // Enforce Manager PIN whenever duration decrease reduces total_bill
            if ($direction === 'decrease' && $newTotalBill < $previousTotalBill) {
                $matchingManager = ManagerPinVerificationService::verify(
                    $user,
                    $validated['manager_pin'] ?? null,
                    $businessId ? (int)$businessId : null,
                    (int)$booking->affiliate_id,
                    'Pengurangan durasi yang menurunkan total tagihan memerlukan otorisasi PIN Manager.',
                    $request->ip(),
                    'DurationAdjustment'
                );
                $managerApprovedBy = $matchingManager->id;
            }

            // Audit Net Payments Received
            $inflowPaid = (float)$booking->payments()
                ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange'])
                ->where(fn($q) => $q->whereNull('status')->orWhere('status', 'completed'))
                ->sum('amount');
            $outflowRefunds = (float)$booking->payments()
                ->where('type', 'refund')
                ->where(fn($q) => $q->whereNull('status')->orWhere('status', 'completed'))
                ->sum('amount');
            $alreadyPaidNet = max(0, $inflowPaid - $outflowRefunds);

            $paymentTx = null;

            if ($feeDelta > 0) {
                // Additional Payment Needed (Price Increased)
                if ($request->filled('payment_method_id')) {
                    $paymentMethod = PaymentMethod::findOrFail($request->payment_method_id);
                    $isCash = strtoupper($paymentMethod->code) === 'CASH' || stripos($paymentMethod->name, 'tunai') !== false;

                    $branchId = (int)($booking->affiliate_id ?? $request->header('X-Branch-Id') ?? $user->affiliate_id);

                    // Enforce business & branch payment method availability
                    $business = Business::find($booking->business_id);
                    if ($business && $paymentMethod && !$business->isPaymentMethodEnabled($paymentMethod, $branchId)) {
                        throw ValidationException::withMessages([
                            'payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
                        ]);
                    }

                    $activeShift = ShiftEnforcementService::requireActiveShift(
                        $branchId,
                        $user,
                        $isCash,
                        'memproses biaya penambahan durasi sewa di kasir'
                    );

                    if ($isCash) {
                        $cashReceived = (float)($request->cash_received ?? $feeDelta);
                        if ($cashReceived < $feeDelta) {
                            throw ValidationException::withMessages([
                                'cash_received' => ['Uang tunai yang diterima kurang dari biaya penambahan durasi.'],
                            ]);
                        }
                        $cashChange = max(0, $cashReceived - $feeDelta);
                    } else {
                        $cashReceived = $feeDelta;
                        $cashChange = 0.00;
                    }

                    $datePrefix = Carbon::now()->format('ymd');
                    $countToday = PaymentTransaction::withoutGlobalScopes()
                        ->where('business_id', $booking->business_id ?? $user->business_id)
                        ->whereDate('transacted_at', Carbon::today())
                        ->count() + 1;
                    $randomSuffix = strtoupper(\Illuminate\Support\Str::random(3));
                    $txCode = sprintf('TX-EXT-%s-%04d-%s', $datePrefix, $countToday, $randomSuffix);
                    $receiptNumber = sprintf('REC-EXT-%s-%s-%s', $booking->affiliate->code ?? 'PST', $datePrefix, strtoupper(\Illuminate\Support\Str::random(4)));

                    $paymentTx = PaymentTransaction::create([
                        'business_id' => $booking->business_id ?? $user->business_id,
                        'branch_id' => $booking->affiliate_id,
                        'shift_id' => $activeShift->id,
                        'status' => 'completed',
                        'transaction_code' => $txCode,
                        'receipt_number' => $receiptNumber,
                        'idempotency_key' => $idempotencyKey,
                        'booking_id' => $booking->id,
                        'payment_method_id' => $paymentMethod->id,
                        'processed_by' => $user->id,
                        'type' => 'extension',
                        'direction' => 'inflow',
                        'amount' => $feeDelta,
                        'cash_received' => $cashReceived,
                        'cash_change' => $cashChange,
                        'notes' => $request->notes ?? "Pembayaran penambahan durasi {$hoursDelta} jam",
                        'transacted_at' => Carbon::now(),
                    ]);
                }
            } elseif ($feeDelta < 0) {
                // Price Decreased -> Check if Overpayment / Refund is Produced
                if ($alreadyPaidNet > $newTotalBill) {
                    $refundAmount = $alreadyPaidNet - $newTotalBill;

                    $branchId = (int)($booking->affiliate_id ?? $request->header('X-Branch-Id') ?? $user->affiliate_id);

                    // Resolve payment method for refund
                    $paymentMethodId = $request->payment_method_id ?? $booking->payments()->where('direction', 'inflow')->latest('id')->value('payment_method_id') ?? 1;
                    $paymentMethod = PaymentMethod::find($paymentMethodId) ?? PaymentMethod::first();

                    // Enforce business & branch payment method availability
                    $business = Business::find($booking->business_id);
                    if ($business && $paymentMethod && !$business->isPaymentMethodEnabled($paymentMethod, $branchId)) {
                        throw ValidationException::withMessages([
                            'payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
                        ]);
                    }

                    $activeShift = ShiftEnforcementService::requireActiveShift(
                        $branchId,
                        $user,
                        true,
                        'mengeluarkan pengembalian dana (refund) dari laci kasir'
                    );

                    // Record Outflow Refund PaymentTransaction
                    $datePrefix = Carbon::now()->format('ymd');
                    $countToday = PaymentTransaction::withoutGlobalScopes()
                        ->where('business_id', $booking->business_id ?? $user->business_id)
                        ->whereDate('transacted_at', Carbon::today())
                        ->count() + 1;
                    $randomSuffix = strtoupper(\Illuminate\Support\Str::random(3));
                    $txCode = sprintf('TX-REF-%s-%04d-%s', $datePrefix, $countToday, $randomSuffix);
                    $receiptNumber = sprintf('REC-REF-%s-%s-%s', $booking->affiliate->code ?? 'PST', $datePrefix, strtoupper(\Illuminate\Support\Str::random(4)));
                    $latestPaymentId = $booking->payments()->where('direction', 'inflow')->latest('id')->value('id');

                    $paymentMethodId = $request->payment_method_id ?? $booking->payments()->where('direction', 'inflow')->latest('id')->value('payment_method_id') ?? 1;

                    $paymentTx = PaymentTransaction::create([
                        'business_id' => $booking->business_id ?? $user->business_id,
                        'branch_id' => $booking->affiliate_id,
                        'shift_id' => $activeShift->id,
                        'status' => 'completed',
                        'transaction_code' => $txCode,
                        'receipt_number' => $receiptNumber,
                        'idempotency_key' => $idempotencyKey,
                        'booking_id' => $booking->id,
                        'payment_method_id' => $paymentMethodId,
                        'processed_by' => $user->id,
                        'reversal_of_id' => $latestPaymentId,
                        'type' => 'refund',
                        'direction' => 'outflow',
                        'amount' => $refundAmount,
                        'cash_received' => 0.00,
                        'cash_change' => 0.00,
                        'notes' => $request->reason ?? "Pengembalian dana pengurangan durasi {$hoursDelta} jam",
                        'transacted_at' => Carbon::now(),
                    ]);
                }
            }

            $booking->recalculateFinancials();

            // Record Extension/Adjustment History
            $extension = RentalExtension::create([
                'booking_id' => $booking->id,
                'booking_item_id' => $isTargetAddon ? $targetItem->id : null,
                'extended_by' => $user->id,
                'adjustment_type' => $direction,
                'hours_delta' => $signedDelta,
                'fee_delta' => $feeDelta,
                'reason' => $validated['reason'] ?? null,
                'manager_approved_by' => $managerApprovedBy,
                'previous_end_at' => $previousEndAt,
                'new_end_at' => $newEndAt,
                'additional_hours' => abs($signedDelta),
                'additional_fee' => max(0, $feeDelta),
            ]);

            // Immutable Audit Log
            AuditLogService::log(
                $user,
                $direction === 'increase' ? 'booking.duration_increased' : 'booking.duration_reduced',
                'RentalExtension',
                $extension->id,
                [
                    'booking_code' => $booking->booking_code,
                    'target' => $isTargetAddon ? 'addon' : 'primary',
                    'item_name' => $targetModel->name,
                    'hours_delta' => $signedDelta,
                    'fee_delta' => $feeDelta,
                    'new_total_bill' => $newTotalBill,
                    'new_end_at' => $newEndAt->toDateTimeString(),
                    'refund_issued' => $paymentTx?->type === 'refund',
                    'manager_approved_by' => $managerApprovedBy,
                ],
                [
                    'previous_end_at' => $previousEndAt->toDateTimeString(),
                ],
                $request->ip(),
                $booking->affiliate_id
            );

            $actionText = ($direction === 'increase') ? 'ditambah' : 'dikurangi';
            $targetText = $isTargetAddon ? "add-on {$targetModel->name}" : "perangkat utama {$targetModel->name}";

            return response()->json([
                'success' => true,
                'message' => "Durasi sewa {$targetText} berhasil {$actionText} {$hoursDelta} jam.",
                'data' => [
                    'booking' => $booking->fresh(['items.asset.model.category', 'extensions', 'payments.paymentMethod']),
                    'adjustment' => $extension,
                    'payment' => $paymentTx,
                    'fee_delta' => $feeDelta,
                    'new_end_at' => $newEndAt->toIso8601String(),
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
            'frp_cleared' => 'nullable|boolean',
            'account_removed' => 'nullable|boolean',
            'checklist_results' => 'required|array',
            'checklist_results.icloud_removed' => 'nullable|boolean',
            'checklist_results.account_quarantined' => 'nullable|boolean',
            'checklist_results.account_status' => 'nullable|string',
            'checklist_results.device_platform' => 'nullable|string',
            'checklist_results.frp_removed' => 'nullable|boolean',
            'checklist_results.google_account_removed' => 'nullable|boolean',
            'checklist_results.frp_cleared' => 'nullable|boolean',
            'checklist_results.account_removed' => 'nullable|boolean',
            'accessories_returned' => 'nullable|array',
            'damage_fee' => 'nullable|numeric|min:0',
            'late_fee' => 'nullable|numeric|min:0',
            'manager_pin' => 'nullable|string',
            'addons_returned' => 'nullable|boolean',
            'returned_addon_ids' => 'nullable|array',
            'returned_addon_ids.*' => 'integer',
            'penalty_payment_method_id' => 'nullable|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'staff_notes' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $booking = Booking::with(['items.asset.model.category', 'items.assetModel', 'addonItems'])->findOrFail($id);

        $data = $request->all();
        if ($request->hasHeader('X-Idempotency-Key') && empty($data['idempotency_key'])) {
            $data['idempotency_key'] = $request->header('X-Idempotency-Key');
        }

        $inspection = $this->returnService->processReturn($booking, $data, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Pengembalian unit berhasil diproses dan unit dikembalikan ke status siap/perbaikan.',
            'data' => $inspection,
        ]);
    }

    /**
     * Get available candidate replacement assets for a booking.
     */
    public function availableReplacements(Request $request, int $id): JsonResponse
    {
        $booking = Booking::with(['items.asset.model', 'affiliate'])->findOrFail($id);

        $currentAssetId = $request->query('asset_id') ? (int)$request->query('asset_id') : null;
        $data = $this->exchangeService->getAvailableReplacements($booking, $currentAssetId);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Process rental unit exchange.
     */
    public function exchange(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'old_asset_id' => 'required|integer|exists:assets,id',
            'new_asset_id' => 'required|integer|exists:assets,id',
            'reported_issue_category' => 'required|in:camera,screen,battery,network,audio,system,other',
            'customer_complaint' => 'required|string',
            'staff_diagnosis' => 'nullable|string',
            'old_unit_condition_grade' => 'nullable|in:A,B,C',
            'icloud_signout_verified' => 'nullable|boolean',
            'old_asset_disposition' => 'nullable|in:maintenance,quarantine,ready',
            'fee_waived' => 'nullable|boolean',
            'waiver_reason' => 'nullable|string|max:255',
            'manager_pin' => 'nullable|string',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $booking = Booking::with(['items.asset.model', 'affiliate'])->findOrFail($id);

        $result = $this->exchangeService->executeExchange($booking, $request->all(), $request->user());

        return response()->json($result);
    }

    /**
     * List ready physical units matching a scheduled booking's model & branch for check-in assignment.
     */
    public function availableUnits(Request $request, int $id): JsonResponse
    {
        $booking = Booking::with(['assetModel', 'assetUnit', 'items.asset.model'])->findOrFail($id);

        $startOverride = null;
        $endOverride = null;
        if ($request->boolean('start_now') || $request->filled('new_start_at')) {
            $startOverride = $request->filled('new_start_at')
                ? Carbon::parse($request->input('new_start_at'))
                : Carbon::now();
            $keepOriginalEndAt = $request->boolean('keep_original_end_at');
            if ($keepOriginalEndAt && $booking->end_at) {
                $endOverride = Carbon::parse($booking->end_at);
            } else {
                $durationHours = max(1, (int) ($booking->duration_hours ?: Carbon::parse($booking->start_at)->diffInHours(Carbon::parse($booking->end_at))));
                $endOverride = $startOverride->copy()->addHours($durationHours);
            }
        }

        $units = $this->availabilityService->getReadyPhysicalUnitsForBooking($booking, $startOverride, $endOverride);

        return response()->json([
            'success' => true,
            'data' => $units,
        ]);
    }

    /**
     * Check-in / Handover a scheduled booking (`booked` -> `rented`) and assign physical unit if floating.
     */
    public function checkIn(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'asset_unit_id' => 'nullable|integer|exists:assets,id',
            'asset_id' => 'nullable|integer|exists:assets,id',
            'collateral_type' => 'nullable|string|max:50',
            'collateral_storage_slot' => 'nullable|string|max:100',
            'start_now' => 'nullable|boolean',
            'keep_original_end_at' => 'nullable|boolean',
        ]);

        $booking = Booking::findOrFail($id);
        $updated = $this->bookingService->checkInBooking($booking, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Unit berhasil diserahkan dan sewa kini aktif.',
            'data' => $updated,
        ]);
    }

    /**
     * Reschedule a booked reservation to a new start time with conflict-safe availability validation.
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'new_start_at' => 'required|date',
            'keep_original_end_at' => 'nullable|boolean',
        ]);

        $booking = Booking::findOrFail($id);
        $updated = $this->bookingService->rescheduleBooking(
            $booking,
            Carbon::parse($validated['new_start_at'])->utc(),
            (bool) ($validated['keep_original_end_at'] ?? false),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Jadwal booking berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    /**
     * Manually mark a scheduled booking as `no_show`.
     */
    public function markNoShow(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $booking = Booking::findOrFail($id);
        $updated = $this->bookingService->markNoShow(
            $booking,
            $request->user(),
            $validated['reason'] ?? null,
            false
        );

        return response()->json([
            'success' => true,
            'message' => 'Booking telah ditandai sebagai No-Show dan slot unit dilepas.',
            'data' => $updated,
        ]);
    }

    /**
     * Cancel a scheduled booking (`booked` -> `cancelled`), release reserved inventory,
     * and optionally refund any paid DP with Manager PIN verification.
     */
    public function cancel(Request $request, int $booking): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
            'refund_dp' => 'nullable|boolean',
            'manager_pin' => 'nullable|string',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
        ]);

        $bookingModel = Booking::findOrFail($booking);
        $updated = $this->bookingService->cancelBooking(
            $bookingModel,
            $request->user(),
            $validated['reason'] ?? null,
            (bool) ($validated['refund_dp'] ?? false),
            $validated['manager_pin'] ?? null,
            isset($validated['payment_method_id']) ? (int) $validated['payment_method_id'] : null
        );

        return response()->json([
            'success' => true,
            'message' => 'Booking berhasil dibatalkan dan slot unit dilepas.',
            'data' => $updated,
        ]);
    }

    /**
     * Manually extend the pickup grace period for a scheduled booking.
     */
    public function extendGrace(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'additional_minutes' => 'nullable|integer|min:5|max:1440',
            'grace_expires_at' => 'nullable|date|after:now',
        ]);

        $booking = Booking::findOrFail($id);
        $newGraceAt = !empty($validated['grace_expires_at'])
            ? Carbon::parse($validated['grace_expires_at'])
            : null;
        $updated = $this->bookingService->extendGracePeriod(
            $booking,
            $request->user(),
            isset($validated['additional_minutes']) ? (int) $validated['additional_minutes'] : null,
            $newGraceAt
        );

        return response()->json([
            'success' => true,
            'message' => 'Batas waktu ambil unit berhasil diperpanjang.',
            'data' => $updated,
        ]);
    }
}
