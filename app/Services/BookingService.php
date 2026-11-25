<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\ShiftEnforcementService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        protected VoucherService $voucherService,
        protected PricingService $pricingService,
        protected BookingAvailabilityService $availabilityService,
        protected LateFeeCalculatorService $lateFeeCalculator
    ) {}

    protected function buildLateFeeContractColumns(
        ?Asset $asset,
        ?AssetModel $model,
        ?Business $business,
        ?array $rateSnapshot = null
    ): array {
        $tier = !empty($rateSnapshot['tier_id']) ? PricingTier::find($rateSnapshot['tier_id']) : null;
        $snap = $this->lateFeeCalculator->buildContractSnapshot($asset, $model, $business, $tier);
        $mergedSnapshot = array_merge($rateSnapshot ?? [], $snap);

        return [
            'rate_snapshot' => $mergedSnapshot,
            'applied_hourly_late_fee' => $snap['applied_hourly_late_fee'],
            'applied_grace_period_minutes' => $snap['applied_grace_period_minutes'],
            'applied_late_fee_grace_mode' => $snap['applied_late_fee_grace_mode'],
            'applied_daily_rate_cap' => $snap['applied_daily_rate_cap'],
            'applied_late_fee_rounding_mode' => $snap['applied_late_fee_rounding_mode'],
        ];
    }

    /**
     * Create an atomic counter rental with immediate activation.
     * Collateral is recorded and locked into safe slot, assets locked to rented, payment ledger written.
     */
    public function createCounterRental(array $data, User $cashier): Booking
    {
        return DB::transaction(function () use ($data, $cashier) {
            [$affiliate, $businessId] = $this->resolveAuthorizedBranchAndBusiness($data, $cashier);
            $affiliateId = (int) $affiliate->id;

            $rawPrimaryIds = (array)($data['asset_ids'] ?? []);
            $rawAddonIds = (array)($data['addon_asset_ids'] ?? []);
            $assetIds = array_values(array_unique(array_filter(array_merge($rawPrimaryIds, $rawAddonIds))));

            // Strict Pre-Checkout Shift Enforcement Check: Ensure Cash Drawer Shift is OPEN before anything is processed
            $activeShift = ShiftEnforcementService::requireActiveShift(
                $affiliateId,
                $cashier,
                false,
                'memproses transaksi'
            );

            // 1. Ensure or find Customer scoped to business
            $customer = null;
            if (!empty($data['customer_id'])) {
                $customerQuery = Customer::query();
                if ($businessId) {
                    $customerQuery->where('business_id', $businessId);
                }
                $customer = $customerQuery->find($data['customer_id']);
            }

            if (!$customer) {
                $customerAttrs = ['phone' => $data['customer_phone']];
                if ($businessId) {
                    $customerAttrs['business_id'] = $businessId;
                }

                $customer = Customer::firstOrCreate(
                    $customerAttrs,
                    [
                        'name' => $data['customer_name'],
                        'email' => $data['customer_email'] ?? null,
                        'address' => $data['customer_address'] ?? null,
                    ]
                );
            }

            // Sync updated contact/address info if cashier edited them
            $updates = [];
            if (!empty($data['customer_name']) && $customer->name !== $data['customer_name']) {
                $updates['name'] = $data['customer_name'];
            }
            if (!empty($data['customer_phone']) && $customer->phone !== $data['customer_phone']) {
                $updates['phone'] = $data['customer_phone'];
            }
            if (!empty($data['customer_address']) && $customer->address !== $data['customer_address']) {
                $updates['address'] = $data['customer_address'];
            }
            if (!empty($updates)) {
                $customer->update($updates);
            }

            if ($customer->is_blacklisted) {
                throw ValidationException::withMessages([
                    'customer' => ['Pelanggan ini masuk dalam daftar hitam (Blacklist): ' . $customer->blacklist_reason],
                ]);
            }

            // Record customer identity photo and document collateral if provided
            $identityFilePath = null;
            if (!empty($data['identity_photo_base64']) || !empty($data['identity_photo'])) {
                $photoData = $data['identity_photo_base64'] ?? $data['identity_photo'];
                if (is_string($photoData)) {
                    $ext = 'jpg';
                    if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $type)) {
                        $photoData = substr($photoData, strpos($photoData, ',') + 1);
                        $ext = strtolower($type[1]);
                    }
                    $decoded = base64_decode($photoData);
                    if ($decoded === false) {
                        $decoded = $photoData;
                    }
                    $filename = 'id_' . $customer->id . '_' . time() . '_' . Str::random(6) . '.' . $ext;
                    $identityFilePath = 'customer_identities/' . $filename;
                    Storage::disk('local')->put($identityFilePath, $decoded);
                }
            }

            if (!empty($data['identity_number'])) {
                CustomerIdentity::updateOrCreate(
                    [
                        'customer_id' => $customer->id,
                        'identity_type' => $data['identity_type'] ?? 'KTP',
                        'identity_number' => $data['identity_number'],
                    ],
                    array_filter([
                        'file_path' => $identityFilePath,
                        'is_verified' => true,
                    ])
                );
            }

            // 2. Lock and verify Assets with Pessimistic Concurrency (SELECT ... FOR UPDATE)
            $assetQuery = Asset::withoutGlobalScopes()
                ->with(['model.category'])
                ->whereIn('id', $assetIds)
                ->where('affiliate_id', $affiliateId);

            if ($businessId) {
                $assetQuery->where('business_id', $businessId);
            }

            $assets = $assetQuery->lockForUpdate()->get();

            if ($assets->count() !== count($assetIds)) {
                throw ValidationException::withMessages([
                    'asset_ids' => ['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.'],
                ]);
            }

            $requestedCounterModelId = !empty($data['asset_model_id'])
                ? (int) $data['asset_model_id']
                : (!empty($data['device_model_id']) ? (int) $data['device_model_id'] : null);
            if ($requestedCounterModelId) {
                $validCounterModel = AssetModel::withoutGlobalScopes()
                    ->when($businessId, fn ($q) => $q->ownedByBusiness((int) $businessId))
                    ->where('is_active', true)
                    ->where('id', $requestedCounterModelId)
                    ->exists();
                $matchesSelectedPrimary = $assets->contains(fn ($a) => (int) $a->model_id === $requestedCounterModelId);
                if (!$validCounterModel || !$matchesSelectedPrimary) {
                    throw ValidationException::withMessages([
                        'asset_model_id' => ['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.'],
                    ]);
                }
            }

            foreach ($assets as $asset) {
                if ($asset->status !== 'ready') {
                    $modelName = $asset->model ? $asset->model->name : 'Unit Gadget';
                    throw ValidationException::withMessages([
                        'asset_ids' => ["Perangkat {$asset->asset_code} ({$modelName}) sedang tidak siap (status: {$asset->status})."],
                    ]);
                }
            }

            // 2a. Resolve Rental Window & Lock Model/Branch Inventory Against Concurrent Double-Booking
            $durationUnit = $data['duration_unit'] ?? 'hour';
            $durationValue = (int)($data['duration_value'] ?? $data['duration_hours'] ?? 1);
            if (!empty($data['duration_hours']) && empty($data['duration_unit'])) {
                $durationUnit = 'hour';
                $durationValue = (int)$data['duration_hours'];
            }
            $durationHours = $this->pricingService->computeDurationHours($durationUnit, $durationValue);
            $startAt = !empty($data['start_at']) ? Carbon::parse($data['start_at'])->utc() : Carbon::now()->utc();
            $endAt = $this->pricingService->computeEndAt($startAt, $durationUnit, $durationValue);

            $involvedModelIds = $assets->pluck('model_id')->filter()->map(fn ($id) => (int)$id)->unique()->values()->all();
            if (!empty($involvedModelIds)) {
                AssetModel::withoutGlobalScopes()
                    ->whereIn('id', $involvedModelIds)
                    ->lockForUpdate()
                    ->get(['id']);

                Asset::withoutGlobalScopes()
                    ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                    ->where('affiliate_id', $affiliateId)
                    ->whereIn('model_id', $involvedModelIds)
                    ->lockForUpdate()
                    ->get(['id']);
            }

            $this->availabilityService->assertAssetsAvailableForWindow(
                $affiliateId,
                (int)($businessId ?? $affiliate->business_id),
                $assets,
                $startAt,
                $endAt,
                null,
                'asset_ids'
            );

            // Classify assets into primary devices and add-on items
            $rawAddonInts = array_map('intval', $rawAddonIds);
            $primaryAssets = [];
            $addonAssets = [];
            foreach ($assets as $asset) {
                $isAddon = in_array((int)$asset->id, $rawAddonInts, true) || ($asset->model && $asset->model->isAddon());
                if ($isAddon) {
                    $addonAssets[] = $asset;
                } else {
                    $primaryAssets[] = $asset;
                }
            }

            // 2b. Validate Business Policies (Add-ons and Identity Document Limit)
            $business = $businessId ? \App\Models\Business::find($businessId) : null;
            if ($business) {
                // Policy: Add-on enable/disable and quantity limit
                if (!$business->addons_enabled && count($addonAssets) > 0) {
                    throw ValidationException::withMessages([
                        'addon_asset_ids' => ['Fitur sewa add-on / aksesoris sedang dinonaktifkan untuk bisnis ini.'],
                    ]);
                }

                if ($business->max_addons_per_rental !== null && count($addonAssets) > $business->max_addons_per_rental) {
                    throw ValidationException::withMessages([
                        'addon_asset_ids' => [
                            'Jumlah unit add-on (' . count($addonAssets) . ') melebihi batas maksimal yang diizinkan (' . $business->max_addons_per_rental . ' unit per transaksi).'
                        ],
                    ]);
                }

                // Policy: Identity Document Rental Limit (Applies only to PRIMARY devices)
                if ($business->id_document_limit_enabled) {
                    $identityNumber = !empty($data['identity_number']) ? trim($data['identity_number']) : null;
                    if (!$identityNumber) {
                        $existingIdDoc = CustomerIdentity::where('customer_id', $customer->id)->latest()->first();
                        $identityNumber = $existingIdDoc?->identity_number;
                    }

                    if ($identityNumber) {
                        $normalizedId = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $identityNumber));
                        $relatedCustomerIds = CustomerIdentity::whereRaw("UPPER(REPLACE(REPLACE(identity_number, ' ', ''), '-', '')) = ?", [$normalizedId])
                            ->pluck('customer_id')
                            ->push($customer->id)
                            ->unique()
                            ->toArray();

                        $activeBookingIds = Booking::withoutGlobalScopes()
                            ->where('business_id', $businessId)
                            ->whereIn('customer_id', $relatedCustomerIds)
                            ->whereIn('status', ['rented', 'reserved', 'active', 'confirmed'])
                            ->pluck('id');

                        $activePrimaryCount = BookingItem::whereIn('booking_id', $activeBookingIds)
                            ->where(function ($q) {
                                $q->where('item_type', 'primary')->orWhereNull('item_type');
                            })
                            ->where(function ($q) {
                                $q->where('status', 'active')->orWhereNull('status');
                            })
                            ->count();

                        $newPrimaryCount = count($primaryAssets);
                        $maxLimit = (int)($business->max_primary_devices_per_id ?? 1);

                        if (($activePrimaryCount + $newPrimaryCount) > $maxLimit) {
                            $maskedId = strlen($identityNumber) > 8
                                ? substr($identityNumber, 0, 4) . '****' . substr($identityNumber, -4)
                                : $identityNumber;
                            throw ValidationException::withMessages([
                                'identity_number' => [
                                    "Batas kuota sewa tercapai. Dokumen identitas ({$maskedId}) telah memiliki {$activePrimaryCount} perangkat utama aktif (Maksimal: {$maxLimit} unit utama). Selesaikan atau kembalikan sewa sebelumnya."
                                ],
                            ]);
                        }
                    }
                }
            }

            // 3. Duration & Time Calculation (already resolved in step 2a for overlap check)

            // 4. Subtotal calculation based on Pricing Tiers or default rates
            $primarySubtotal = 0.00;
            $addonSubtotal = 0.00;
            $addonAssetIdsList = array_map(fn($a) => (int)$a->id, $addonAssets);
            $itemPrices = [];
            $itemSnapshots = [];
            foreach ($assets as $asset) {
                $isAddonItem = in_array((int)$asset->id, $addonAssetIdsList, true) || ($asset->model && $asset->model->isAddon());
                $passedPrice = null;
                if (!empty($data['unit_prices']) && is_array($data['unit_prices'])) {
                    $rawPassed = $data['unit_prices'][$asset->id] ?? $data['unit_prices'][(string)$asset->id] ?? null;
                    $passedPrice = $rawPassed !== null ? (float)$rawPassed : null;
                }

                [$unitPrice, $snapshot] = $this->resolveAuthoritativeItemPrice(
                    $asset->model,
                    $durationUnit,
                    $durationValue,
                    $startAt,
                    $durationHours,
                    $passedPrice,
                    $isAddonItem,
                    $data,
                    $cashier,
                    $affiliate,
                    $businessId ? (int)$businessId : null
                );

                if (in_array((int)$asset->id, $addonAssetIdsList, true)) {
                    $addonSubtotal += $unitPrice;
                } else {
                    $primarySubtotal += $unitPrice;
                }

                $itemPrices[$asset->id] = $unitPrice;
                $itemSnapshots[$asset->id] = $snapshot;
            }

            $subtotal = $primarySubtotal + $addonSubtotal;

            // 5. Discount Calculation (Voucher or Manual)
            [$discountType, $discountAmount, $voucherId, $approvedBy] = $this->resolveDiscountAndApprover(
                $data,
                $subtotal,
                $customer,
                $affiliate,
                $businessId ? (int)$businessId : null,
                $cashier
            );

            $depositFee = (float)($data['deposit_fee'] ?? 0.00);
            $totalBill = max(0, $subtotal + $depositFee - $discountAmount);

            // 6. Generate Booking Code: RC-YYMMDD-XXXX-YYY (Tenant scoped + entropy)
            $datePrefix = Carbon::now()->format('ymd');
            $countToday = Booking::withoutGlobalScopes()
                ->where('business_id', $businessId)
                ->whereDate('created_at', Carbon::today())
                ->count() + 1;
            $bookingCode = sprintf('RC-%s-%04d-%s', $datePrefix, $countToday, strtoupper(Str::random(3)));

            $primaryAssetModelId = !empty($primaryAssets)
                ? $primaryAssets[0]->model_id
                : ($assets->first()?->model_id);
            $primaryAssetUnitId = !empty($primaryAssets)
                ? $primaryAssets[0]->id
                : ($assets->first()?->id);

            $idempotencyKey = !empty($data['idempotency_key']) ? (string)$data['idempotency_key'] : null;

            // 7. Create Booking (Direct activation: status = 'rented')
            $idempotencyKey = !empty($data['idempotency_key']) ? (string)$data['idempotency_key'] : null;
            $business = $businessId ? Business::find($businessId) : null;
            $collateral = $this->resolveCollateralData($business, $data, true);

            $booking = Booking::withoutGlobalScopes()->create([
                'business_id' => $businessId,
                'booking_code' => $bookingCode,
                'idempotency_key' => $idempotencyKey,
                'affiliate_id' => $affiliateId,
                'asset_model_id' => $primaryAssetModelId,
                'asset_unit_id' => $primaryAssetUnitId,
                'customer_id' => $customer->id,
                'created_by' => $cashier->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'duration_hours' => $durationHours,
                'duration_unit' => $durationUnit,
                'duration_value' => $durationValue,
                'status' => 'rented',
                'subtotal_amount' => $subtotal,
                'primary_subtotal' => $primarySubtotal,
                'addon_subtotal' => $addonSubtotal,
                'deposit_fee' => $depositFee,
                'discount_type' => $discountType,
                'discount_amount' => $discountAmount,
                'voucher_id' => $voucherId,
                'manual_discount_reason' => $data['manual_discount_reason'] ?? null,
                'discount_approved_by' => $approvedBy,
                'total_bill' => $totalBill,
                'remaining_balance' => 0.00,
                'payment_status' => 'paid',
                'collateral_type' => $collateral['collateral_type'],
                'collateral_type_id' => $collateral['collateral_type_id'],
                'collateral_type_name' => $collateral['collateral_type_name'],
                'collateral_secured' => $collateral['collateral_secured'],
                'collateral_storage_slot' => $collateral['collateral_storage_slot'],
                'collateral_deposit_amount' => $collateral['collateral_deposit_amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            // 8. Create Booking Items & transition assets to 'rented'
            foreach ($assets as $asset) {
                $itemType = in_array((int)$asset->id, $addonAssetIdsList, true) ? 'addon' : 'primary';
                $contractCols = $this->buildLateFeeContractColumns(
                    $asset,
                    $asset->model,
                    $business,
                    $itemSnapshots[$asset->id] ?? null
                );
                BookingItem::create(array_merge([
                    'booking_id' => $booking->id,
                    'asset_id' => $asset->id,
                    'asset_model_id' => $asset->model_id,
                    'item_type' => $itemType,
                    'unit_price' => $itemPrices[$asset->id],
                    'duration_unit' => $durationUnit,
                    'duration_value' => $durationValue,
                ], $contractCols));

                $asset->update(['status' => 'rented']);
            }

            // 9. Increment voucher usage and log
            if ($voucherId) {
                $voucher = Voucher::find($voucherId);
                $voucher->increment('usage_count');

                VoucherUsage::create([
                    'voucher_id' => $voucherId,
                    'booking_id' => $booking->id,
                    'customer_id' => $customer->id,
                    'discount_amount' => $discountAmount,
                    'used_at' => Carbon::now(),
                ]);
            }

            // 10. Record Single Source of Truth Payment Transaction
            $paymentMethod = PaymentMethod::resolveFromInput(
                $data['payment_method_id'] ?? null,
                $data['payment_method'] ?? null,
                $data['payment_channel'] ?? null
            );
            if (!$paymentMethod) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ['Metode pembayaran tidak valid atau tidak ditemukan.'],
                ]);
            }
            $data['payment_method_id'] = $paymentMethod->id;
            $isCash = strtoupper($paymentMethod->code) === 'CASH' || stripos($paymentMethod->name, 'tunai') !== false;

            // Enforce business & branch payment method availability
            $business = Business::find($businessId);
            if ($business && !$business->isPaymentMethodEnabled($paymentMethod, (int)$affiliateId)) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
                ]);
            }

            // Re-verify Active Shift before committing direct rental payment
            if (!empty($data['shift_id'])) {
                $activeShift = \App\Models\CashDrawerShift::where('id', $data['shift_id'])
                    ->where('branch_id', (int)$affiliateId)
                    ->where('status', 'open')
                    ->first();
            }
            $activeShift = $activeShift ?? ShiftEnforcementService::requireActiveShift(
                (int)$affiliateId,
                $cashier,
                $isCash,
                'memproses transaksi'
            );

            if ($isCash && isset($data['cash_received']) && (float)$data['cash_received'] < $totalBill) {
                throw ValidationException::withMessages([
                    'cash_received' => [
                        'Jumlah uang tunai yang diterima (Rp ' . number_format((float)$data['cash_received'], 0, ',', '.') . 
                        ') kurang dari total tagihan (Rp ' . number_format($totalBill, 0, ',', '.') . ').'
                    ],
                ]);
            }

            $cashReceived = $isCash
                ? (isset($data['cash_received']) ? (float)$data['cash_received'] : $totalBill)
                : $totalBill;
            $cashChange = $isCash ? max(0, $cashReceived - $totalBill) : 0.00;

            $this->recordRentalAndDepositTransactions(
                $booking,
                $affiliate,
                $cashier,
                $paymentMethod,
                $isCash,
                $totalBill,
                $depositFee,
                $cashReceived,
                $cashChange,
                'settlement',
                $activeShift?->id,
                'Pembayaran lunas sewa langsung di counter kasir',
                $idempotencyKey,
                !empty($data['external_reference']) ? (string)$data['external_reference'] : null
            );

            // 11. Immutable Security Audit Logging
            AuditLogService::log(
                $cashier,
                'booking.created',
                'Booking',
                $booking->booking_code,
                [
                    'booking_code' => $booking->booking_code,
                    'customer' => $customer->name,
                    'phone' => $customer->phone,
                    'duration_hours' => $durationHours,
                    'duration_unit' => $durationUnit,
                    'duration_value' => $durationValue,
                    'total_bill' => $totalBill,
                    'collateral_storage_slot' => $booking->collateral_storage_slot,
                    'asset_ids' => $assetIds,
                    'discount_amount' => $discountAmount,
                ],
                null,
                request()?->ip(),
                $affiliateId
            );

            return $booking->load(['items.asset.model', 'items.assetModel', 'assetModel', 'assetUnit', 'customer', 'payments.paymentMethod', 'voucher', 'affiliate']);
        });
    }

    /**
     * Create a scheduled reservation (`status = 'booked'`) supporting both Floating Unit and Pinned Unit modes.
     */
    public function createScheduledBooking(array $data, User $cashier): Booking
    {
        return DB::transaction(function () use ($data, $cashier) {
            [$affiliate, $businessId] = $this->resolveAuthorizedBranchAndBusiness($data, $cashier);
            $affiliateId = (int) $affiliate->id;

            $hasPaymentSpec = !empty($data['payment_method_id'])
                || !empty($data['payment_method'])
                || !empty($data['payment_channel']);
            $rawPaidInput = $data['paid_amount'] ?? $data['dp_amount'] ?? null;
            $paidAmountRequested = $rawPaidInput !== null
                ? (float) $rawPaidInput
                : ($hasPaymentSpec ? null : 0.0);

            if ($paidAmountRequested === null || $paidAmountRequested > 0) {
                ShiftEnforcementService::requireActiveShift(
                    $affiliateId,
                    $cashier,
                    false,
                    'memproses pembayaran booking'
                );
            }

            $customer = $this->resolveOrCreateCustomer($data, $businessId);

            // Duration & Schedule Window
            $durationUnit = $data['duration_unit'] ?? 'hour';
            $durationValue = (int) ($data['duration_value'] ?? $data['duration_hours'] ?? 1);
            if (!empty($data['duration_hours']) && empty($data['duration_unit'])) {
                $durationUnit = 'hour';
                $durationValue = (int) $data['duration_hours'];
            }
            $durationHours = $this->pricingService->computeDurationHours($durationUnit, $durationValue);
            $startAt = !empty($data['start_at']) ? Carbon::parse($data['start_at'])->utc() : Carbon::now()->utc();
            $endAt = $this->pricingService->computeEndAt($startAt, $durationUnit, $durationValue);
            $graceExpiresAt = $this->availabilityService->computeGraceExpiresAt($startAt, $affiliate);

            // Resolve Floating vs Pinned Physical Unit
            $rawPrimaryIds = array_values(array_unique(array_filter(array_map(
                'intval',
                (array) ($data['asset_ids'] ?? (!empty($data['asset_unit_id']) ? [$data['asset_unit_id']] : []))
            ))));
            $rawAddonIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['addon_asset_ids'] ?? [])))));

            $requestedModelId = $data['asset_model_id'] ?? $data['device_model_id'] ?? null;
            $isFloating = empty($rawPrimaryIds);

            if ($isFloating) {
                if (!($affiliate->allow_floating_unit_booking ?? true)) {
                    throw ValidationException::withMessages([
                        'asset_ids' => ['Cabang ini mewajibkan pemilihan unit fisik tertentu (Floating Unit dinonaktifkan).'],
                    ]);
                }
                if (empty($requestedModelId)) {
                    throw ValidationException::withMessages([
                        'asset_model_id' => ['Model perangkat wajib dipilih untuk booking floating unit.'],
                    ]);
                }

                $model = AssetModel::withoutGlobalScopes()
                    ->when($businessId, fn ($q) => $q->ownedByBusiness((int) $businessId))
                    ->where('is_active', true)
                    ->find((int) $requestedModelId);
                if (!$model) {
                    throw ValidationException::withMessages([
                        'asset_model_id' => ['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.'],
                    ]);
                }

                // Serialize concurrent bookings at the branch level by locking target model & branch assets
                AssetModel::withoutGlobalScopes()
                    ->where('id', $model->id)
                    ->lockForUpdate()
                    ->get(['id']);

                Asset::withoutGlobalScopes()
                    ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                    ->where('affiliate_id', $affiliateId)
                    ->where('model_id', $model->id)
                    ->lockForUpdate()
                    ->get(['id']);

                $capacity = $this->availabilityService->getBranchModelCapacity(
                    $affiliateId,
                    (int) $model->id,
                    $startAt,
                    $endAt,
                    null,
                    $businessId ? (int) $businessId : null
                );

                if ($capacity['total_units'] < 1) {
                    throw ValidationException::withMessages([
                        'asset_model_id' => ['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.'],
                    ]);
                }

                if ($capacity['available_slots'] < 1) {
                    $conflict = $this->availabilityService->findEarliestConflictForModel(
                        $affiliateId,
                        (int) $model->id,
                        $startAt,
                        $endAt,
                        null,
                        $businessId ? (int) $businessId : null
                    );
                    throw ValidationException::withMessages([
                        'asset_model_id' => [$this->availabilityService->buildConflictMessage($model->name, $conflict, $affiliateId)],
                    ]);
                }

                $primaryAssets = collect();
                $primaryModelId = (int) $model->id;
                $primaryUnitId = null;

                $rawPassedModelPrice = $data['unit_prices']['model_' . $model->id]
                    ?? $data['unit_prices']['primary']
                    ?? null;
                $passedModelPrice = $rawPassedModelPrice !== null ? (float) $rawPassedModelPrice : null;

                [$primaryPrice, $primarySnapshot] = $this->resolveAuthoritativeItemPrice(
                    $model,
                    $durationUnit,
                    $durationValue,
                    $startAt,
                    $durationHours,
                    $passedModelPrice,
                    false,
                    $data,
                    $cashier,
                    $affiliate,
                    $businessId
                );
                $primarySubtotal = $primaryPrice;
            } else {
                $primaryAssets = Asset::withoutGlobalScopes()
                    ->with(['model.category'])
                    ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                    ->whereIn('id', $rawPrimaryIds)
                    ->where('affiliate_id', $affiliateId)
                    ->lockForUpdate()
                    ->get();

                if ($primaryAssets->count() !== count($rawPrimaryIds)) {
                    throw ValidationException::withMessages([
                        'asset_ids' => ['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.'],
                    ]);
                }

                if ($requestedModelId) {
                    $validModel = AssetModel::withoutGlobalScopes()
                        ->when($businessId, fn ($q) => $q->ownedByBusiness((int) $businessId))
                        ->where('is_active', true)
                        ->where('id', (int) $requestedModelId)
                        ->exists();
                    if (!$validModel || $primaryAssets->contains(fn ($a) => (int) $a->model_id !== (int) $requestedModelId)) {
                        throw ValidationException::withMessages([
                            'asset_model_id' => ['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.'],
                        ]);
                    }
                }

                $involvedModelIds = $primaryAssets->pluck('model_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
                if (!empty($involvedModelIds)) {
                    AssetModel::withoutGlobalScopes()
                        ->whereIn('id', $involvedModelIds)
                        ->lockForUpdate()
                        ->get(['id']);

                    Asset::withoutGlobalScopes()
                        ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                        ->where('affiliate_id', $affiliateId)
                        ->whereIn('model_id', $involvedModelIds)
                        ->lockForUpdate()
                        ->get(['id']);
                }

                foreach ($primaryAssets as $asset) {
                    if (!in_array($asset->status, BookingAvailabilityService::RENTABLE_ASSET_STATUSES, true)) {
                        throw ValidationException::withMessages([
                            'asset_ids' => ["Perangkat {$asset->asset_code} tidak dapat dibooking (status: {$asset->status})."],
                        ]);
                    }
                }

                $this->availabilityService->assertAssetsAvailableForWindow(
                    $affiliateId,
                    (int) ($businessId ?? $affiliate->business_id),
                    $primaryAssets,
                    $startAt,
                    $endAt,
                    null,
                    'asset_ids'
                );

                $firstPrimary = $primaryAssets->first();
                $primaryModelId = (int) ($requestedModelId ?? $firstPrimary->model_id);
                $primaryUnitId = (int) $firstPrimary->id;
                $primarySubtotal = 0.0;
            }

            // Load and validate add-on assets if any
            $addonAssets = collect();
            if (!empty($rawAddonIds)) {
                $addonAssets = Asset::withoutGlobalScopes()
                    ->with(['model.category'])
                    ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                    ->whereIn('id', $rawAddonIds)
                    ->where('affiliate_id', $affiliateId)
                    ->lockForUpdate()
                    ->get();

                if ($addonAssets->count() !== count($rawAddonIds)) {
                    throw ValidationException::withMessages([
                        'addon_asset_ids' => ['Unit atau model tidak tersedia di cabang ini pada jadwal yang dipilih.'],
                    ]);
                }

                foreach ($addonAssets as $addon) {
                    if (!$this->pricingService->isAssetAvailable($addon, $startAt, $endAt)) {
                        throw ValidationException::withMessages([
                            'addon_asset_ids' => ["Add-on {$addon->asset_code} tidak tersedia pada jadwal tersebut."],
                        ]);
                    }
                }
            }

            // Enforce business add-on & ID quota rules
            $business = $businessId ? Business::find($businessId) : null;
            if ($business) {
                if (!$business->addons_enabled && $addonAssets->count() > 0) {
                    throw ValidationException::withMessages([
                        'addon_asset_ids' => ['Fitur sewa add-on / aksesoris sedang dinonaktifkan untuk bisnis ini.'],
                    ]);
                }
                if ($business->max_addons_per_rental !== null && $addonAssets->count() > $business->max_addons_per_rental) {
                    throw ValidationException::withMessages([
                        'addon_asset_ids' => ['Jumlah unit add-on melebihi batas maksimal yang diizinkan.'],
                    ]);
                }
            }

            $itemPrices = [];
            $itemSnapshots = [];
            foreach ($primaryAssets as $asset) {
                $rawPassed = $data['unit_prices'][$asset->id] ?? $data['unit_prices'][(string) $asset->id] ?? null;
                $passedPrice = $rawPassed !== null ? (float) $rawPassed : null;
                [$unitPrice, $snapshot] = $this->resolveAuthoritativeItemPrice(
                    $asset->model,
                    $durationUnit,
                    $durationValue,
                    $startAt,
                    $durationHours,
                    $passedPrice,
                    false,
                    $data,
                    $cashier,
                    $affiliate,
                    $businessId
                );
                $primarySubtotal += $unitPrice;
                $itemPrices[$asset->id] = $unitPrice;
                $itemSnapshots[$asset->id] = $snapshot;
            }

            $addonSubtotal = 0.0;
            foreach ($addonAssets as $addon) {
                $rawPassed = $data['unit_prices'][$addon->id] ?? $data['unit_prices'][(string) $addon->id] ?? null;
                $passedPrice = $rawPassed !== null ? (float) $rawPassed : null;
                [$unitPrice, $snapshot] = $this->resolveAuthoritativeItemPrice(
                    $addon->model,
                    $durationUnit,
                    $durationValue,
                    $startAt,
                    $durationHours,
                    $passedPrice,
                    true,
                    $data,
                    $cashier,
                    $affiliate,
                    $businessId
                );
                $addonSubtotal += $unitPrice;
                $itemPrices[$addon->id] = $unitPrice;
                $itemSnapshots[$addon->id] = $snapshot;
            }

            $subtotal = $primarySubtotal + $addonSubtotal;

            [$discountType, $discountAmount, $voucherId, $approvedBy] = $this->resolveDiscountAndApprover(
                $data,
                $subtotal,
                $customer,
                $affiliate,
                $businessId,
                $cashier
            );

            $depositFee = (float) ($data['deposit_fee'] ?? 0.00);
            $totalBill = max(0, $subtotal + $depositFee - $discountAmount);

            // Determine initial payment amount (supports full settlement, partial DP, or unpaid)
            $amountPaidNow = 0.0;
            $resolvedScheduledPaymentMethod = $hasPaymentSpec
                ? PaymentMethod::resolveFromInput(
                    $data['payment_method_id'] ?? null,
                    $data['payment_method'] ?? null,
                    $data['payment_channel'] ?? null
                )
                : null;
            if ($resolvedScheduledPaymentMethod) {
                $data['payment_method_id'] = $resolvedScheduledPaymentMethod->id;
                $amountPaidNow = $paidAmountRequested !== null
                    ? min($totalBill, max(0.0, $paidAmountRequested))
                    : $totalBill;
            } elseif ($hasPaymentSpec) {
                throw ValidationException::withMessages([
                    'payment_method_id' => ['Metode pembayaran tidak valid atau tidak ditemukan.'],
                ]);
            }
            $paymentStatus = $amountPaidNow >= $totalBill && $totalBill > 0
                ? 'paid'
                : ($amountPaidNow > 0 ? 'partial' : ($totalBill <= 0 ? 'paid' : 'unpaid'));
            $remainingBalance = max(0.0, round($totalBill - $amountPaidNow, 2));

            $datePrefix = Carbon::now()->format('ymd');
            $countToday = Booking::withoutGlobalScopes()
                ->where('business_id', $businessId)
                ->whereDate('created_at', Carbon::today())
                ->count() + 1;
            $bookingCode = sprintf('RC-%s-%04d-%s', $datePrefix, $countToday, strtoupper(Str::random(3)));

            $idempotencyKey = !empty($data['idempotency_key']) ? (string)$data['idempotency_key'] : null;

            $business = $businessId ? Business::find($businessId) : null;
            $collateral = $this->resolveCollateralData($business, $data, false);

            $booking = Booking::withoutGlobalScopes()->create([
                'business_id' => $businessId,
                'booking_code' => $bookingCode,
                'idempotency_key' => $idempotencyKey,
                'affiliate_id' => $affiliateId,
                'asset_model_id' => $primaryModelId,
                'asset_unit_id' => $primaryUnitId,
                'customer_id' => $customer->id,
                'created_by' => $cashier->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'grace_expires_at' => $graceExpiresAt,
                'is_overdue_pickup' => false,
                'duration_hours' => $durationHours,
                'duration_unit' => $durationUnit,
                'duration_value' => $durationValue,
                'status' => 'booked',
                'subtotal_amount' => $subtotal,
                'primary_subtotal' => $primarySubtotal,
                'addon_subtotal' => $addonSubtotal,
                'deposit_fee' => $depositFee,
                'discount_type' => $discountType,
                'discount_amount' => $discountAmount,
                'voucher_id' => $voucherId,
                'manual_discount_reason' => $data['manual_discount_reason'] ?? null,
                'discount_approved_by' => $approvedBy,
                'total_bill' => $totalBill,
                'remaining_balance' => $remainingBalance,
                'payment_status' => $paymentStatus,
                'collateral_type' => $collateral['collateral_type'],
                'collateral_type_id' => $collateral['collateral_type_id'],
                'collateral_type_name' => $collateral['collateral_type_name'],
                'collateral_secured' => false,
                'collateral_storage_slot' => $collateral['collateral_storage_slot'],
                'collateral_deposit_amount' => $collateral['collateral_deposit_amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            if ($isFloating) {
                $primaryModel = $primaryModelId ? AssetModel::withoutGlobalScopes()->find($primaryModelId) : null;
                $contractCols = $this->buildLateFeeContractColumns(null, $primaryModel, $business, $primarySnapshot);
                BookingItem::create(array_merge([
                    'booking_id' => $booking->id,
                    'asset_id' => null,
                    'asset_model_id' => $primaryModelId,
                    'item_type' => 'primary',
                    'unit_price' => $primaryPrice,
                    'duration_unit' => $durationUnit,
                    'duration_value' => $durationValue,
                    'status' => 'active',
                ], $contractCols));
            } else {
                foreach ($primaryAssets as $asset) {
                    $contractCols = $this->buildLateFeeContractColumns($asset, $asset->model, $business, $itemSnapshots[$asset->id] ?? null);
                    BookingItem::create(array_merge([
                        'booking_id' => $booking->id,
                        'asset_id' => $asset->id,
                        'asset_model_id' => $asset->model_id,
                        'item_type' => 'primary',
                        'unit_price' => $itemPrices[$asset->id],
                        'duration_unit' => $durationUnit,
                        'duration_value' => $durationValue,
                        'status' => 'active',
                    ], $contractCols));
                }
            }

            foreach ($addonAssets as $addon) {
                $contractCols = $this->buildLateFeeContractColumns($addon, $addon->model, $business, $itemSnapshots[$addon->id] ?? null);
                BookingItem::create(array_merge([
                    'booking_id' => $booking->id,
                    'asset_id' => $addon->id,
                    'asset_model_id' => $addon->model_id,
                    'item_type' => 'addon',
                    'unit_price' => $itemPrices[$addon->id],
                    'duration_unit' => $durationUnit,
                    'duration_value' => $durationValue,
                    'status' => 'active',
                ], $contractCols));
            }

            if ($voucherId) {
                Voucher::find($voucherId)?->increment('usage_count');
                VoucherUsage::create([
                    'voucher_id' => $voucherId,
                    'booking_id' => $booking->id,
                    'customer_id' => $customer->id,
                    'discount_amount' => $discountAmount,
                    'used_at' => Carbon::now(),
                ]);
            }

            if ($amountPaidNow > 0 && !empty($data['payment_method_id'])) {
                $txType = $amountPaidNow < $totalBill ? 'dp' : 'settlement';
                $this->recordBookingPayment(
                    $booking,
                    $affiliate,
                    $cashier,
                    (int) $data['payment_method_id'],
                    $amountPaidNow,
                    isset($data['cash_received']) ? (float) $data['cash_received'] : null,
                    $txType,
                    $data['shift_id'] ?? null,
                    $txType === 'dp' ? 'Pembayaran DP booking terjadwal' : 'Pembayaran lunas booking terjadwal',
                    $idempotencyKey,
                    !empty($data['external_reference']) ? (string)$data['external_reference'] : null
                );
            }

            AuditLogService::log(
                $cashier,
                'booking.scheduled',
                'Booking',
                $booking->booking_code,
                [
                    'booking_code' => $booking->booking_code,
                    'asset_model_id' => $primaryModelId,
                    'asset_unit_id' => $primaryUnitId,
                    'is_floating' => $isFloating,
                    'start_at' => $startAt->toIso8601String(),
                    'grace_expires_at' => $graceExpiresAt->toIso8601String(),
                    'total_bill' => $totalBill,
                    'amount_paid_now' => $amountPaidNow,
                ],
                null,
                request()?->ip(),
                $affiliateId
            );

            return $booking->load([
                'items.asset.model',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'voucher',
                'affiliate',
            ]);
        });
    }

    /**
     * Check-in / Handover a scheduled booking (`booked` / `late_pickup` -> `rented`) and assign a physical unit if floating.
     */
    public function checkInBooking(Booking $booking, array $data, User $staff): Booking
    {
        return DB::transaction(function () use ($booking, $data, $staff) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['business', 'items.asset.model', 'items.assetModel', 'assetModel', 'assetUnit', 'affiliate', 'payments'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover', 'late_pickup'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$lockedBooking->booking_code} tidak dapat diserahkan (status saat ini: {$lockedBooking->status})."],
                ]);
            }

            $startNow = !empty($data['start_now']);
            $keepOriginalEndAt = !empty($data['keep_original_end_at']);
            $now = Carbon::now();
            if ($startNow && Carbon::parse($lockedBooking->start_at)->gt($now)) {
                $this->shiftBookingSchedule($lockedBooking, $now, $keepOriginalEndAt, true);
                $lockedBooking->refresh();
                $lockedBooking->load(['business', 'items.asset.model', 'items.assetModel', 'assetModel', 'assetUnit', 'affiliate', 'payments']);
            }

            $requestedAssetId = $data['asset_unit_id'] ?? $data['asset_id'] ?? null;
            $targetAsset = null;

            if ($requestedAssetId) {
                $targetAsset = Asset::withoutGlobalScopes()
                    ->with('model')
                    ->where('id', (int) $requestedAssetId)
                    ->lockForUpdate()
                    ->first();

                if (!$targetAsset) {
                    throw ValidationException::withMessages([
                        'asset_unit_id' => ['Unit fisik yang dipilih tidak ditemukan.'],
                    ]);
                }
            } elseif ($lockedBooking->asset_unit_id) {
                $targetAsset = Asset::withoutGlobalScopes()
                    ->with('model')
                    ->where('id', (int) $lockedBooking->asset_unit_id)
                    ->lockForUpdate()
                    ->first();
            } else {
                // Auto-assign an available ready physical unit matching the booked model
                $readyCandidates = $this->availabilityService->getReadyPhysicalUnitsForBooking($lockedBooking);
                $firstCandidate = $readyCandidates->first();
                if ($firstCandidate) {
                    $targetAsset = Asset::withoutGlobalScopes()
                        ->with('model')
                        ->where('id', $firstCandidate->id)
                        ->lockForUpdate()
                        ->first();
                }
            }

            if (!$targetAsset) {
                throw ValidationException::withMessages([
                    'asset_unit_id' => ['Tidak ada unit fisik siap sewa yang tersedia untuk model ini pada cabang aktif.'],
                ]);
            }

            if ((int) $targetAsset->affiliate_id !== (int) $lockedBooking->affiliate_id) {
                throw ValidationException::withMessages([
                    'asset_unit_id' => ['Unit fisik yang dipilih tidak berada di cabang booking ini.'],
                ]);
            }

            $expectedModelId = $lockedBooking->device_model_id;
            if ($expectedModelId && (int) $targetAsset->model_id !== (int) $expectedModelId) {
                throw ValidationException::withMessages([
                    'asset_unit_id' => ['Model unit fisik tidak sesuai dengan model perangkat pada booking ini.'],
                ]);
            }

            if ($targetAsset->status !== 'ready') {
                throw ValidationException::withMessages([
                    'asset_unit_id' => ["Unit {$targetAsset->asset_code} sedang tidak siap (status: {$targetAsset->status})."],
                ]);
            }

            $origStartAt = Carbon::parse($lockedBooking->start_at);
            $origEndAt = Carbon::parse($lockedBooking->end_at);
            $startAt = $origStartAt->copy();
            $endAt = $origEndAt->copy();

            $latePickupNotice = null;
            $endTimeModeApplied = 'kept_original';
            $isLateCheckIn = $lockedBooking->status === 'late_pickup'
                || (bool) $lockedBooking->is_late_pickup
                || ($now->gt($origStartAt) && !$startNow);

            if ($isLateCheckIn) {
                $business = $lockedBooking->business ?? ($lockedBooking->business_id ? Business::find($lockedBooking->business_id) : null);
                $policyMode = $business?->late_pickup_end_time_mode ?? 'keep_original_end';

                if ($policyMode === 'shift_if_available') {
                    $durationSeconds = max(3600, $origStartAt->diffInSeconds($origEndAt));
                    $shiftedStart = $now->copy();
                    $shiftedEnd = $now->copy()->addSeconds($durationSeconds);

                    $assetEval = $this->availabilityService->evaluateAssetWindowAvailability(
                        $targetAsset,
                        $shiftedStart,
                        $shiftedEnd,
                        (int) $lockedBooking->id
                    );
                    $modelCap = $this->availabilityService->getBranchModelCapacity(
                        (int) $lockedBooking->affiliate_id,
                        (int) $targetAsset->model_id,
                        $shiftedStart,
                        $shiftedEnd,
                        (int) $lockedBooking->id,
                        $lockedBooking->business_id ? (int) $lockedBooking->business_id : null
                    );

                    if (($assetEval['is_available'] ?? false) && ($modelCap['available_slots'] ?? 0) >= 1) {
                        $startAt = $shiftedStart;
                        $endAt = $shiftedEnd;
                        $endTimeModeApplied = 'shifted';
                        $latePickupNotice = 'Waktu selesai sewa digeser mengikuti waktu serah terima karena unit tersedia.';
                    } else {
                        $endTimeModeApplied = 'kept_original_fallback';
                        $latePickupNotice = 'Jadwal berikutnya penuh pada jendela perpanjangan, sehingga waktu kembali tetap mengikuti jadwal awal.';
                    }
                }
            }

            if (!$this->pricingService->isAssetAvailable($targetAsset, $startAt, $endAt, (int) $lockedBooking->id)) {
                throw ValidationException::withMessages([
                    'asset_unit_id' => ["Unit {$targetAsset->asset_code} memiliki jadwal sewa lain yang bertabrakan."],
                ]);
            }

            $targetAsset->update(['status' => 'rented']);

            $primaryItem = $lockedBooking->items
                ->first(fn ($item) => $item->item_type === 'primary' || $item->item_type === null);
            $businessForCheckIn = $lockedBooking->business ?? ($lockedBooking->business_id ? Business::find($lockedBooking->business_id) : null);
            $targetAsset->loadMissing('model');
            $checkInRateSnapshot = $primaryItem
                ? (is_array($primaryItem->rate_snapshot) ? $primaryItem->rate_snapshot : json_decode($primaryItem->rate_snapshot ?? '[]', true))
                : null;
            $checkInContractCols = $this->buildLateFeeContractColumns(
                $targetAsset,
                $targetAsset->model,
                $businessForCheckIn,
                is_array($checkInRateSnapshot) ? $checkInRateSnapshot : null
            );

            if ($primaryItem) {
                $primaryItem->update(array_merge([
                    'asset_id' => $targetAsset->id,
                    'asset_model_id' => $targetAsset->model_id,
                    'status' => 'active',
                ], $checkInContractCols));
            } else {
                BookingItem::create(array_merge([
                    'booking_id' => $lockedBooking->id,
                    'asset_id' => $targetAsset->id,
                    'asset_model_id' => $targetAsset->model_id,
                    'item_type' => 'primary',
                    'unit_price' => (float) ($lockedBooking->primary_subtotal ?? $lockedBooking->subtotal_amount ?? 0),
                    'duration_unit' => $lockedBooking->duration_unit ?? 'hour',
                    'duration_value' => $lockedBooking->duration_value ?? $lockedBooking->duration_hours,
                    'status' => 'active',
                ], $checkInContractCols));
            }

            $collateralBusiness = $lockedBooking->business_id ? Business::find($lockedBooking->business_id) : null;
            $collateral = $this->resolveCollateralData($collateralBusiness, $data, true, $lockedBooking);

            $bookingUpdates = [
                'status' => 'rented',
                'picked_up_at' => $now,
                'asset_model_id' => $lockedBooking->asset_model_id ?? $targetAsset->model_id,
                'asset_unit_id' => $targetAsset->id,
                'is_overdue_pickup' => false,
                'is_late_pickup' => false,
                'collateral_type' => $collateral['collateral_type'],
                'collateral_type_id' => $collateral['collateral_type_id'],
                'collateral_type_name' => $collateral['collateral_type_name'],
                'collateral_storage_slot' => $collateral['collateral_storage_slot'],
                'collateral_deposit_amount' => $collateral['collateral_deposit_amount'],
                'collateral_secured' => $collateral['collateral_secured'],
            ];

            if ($endTimeModeApplied === 'shifted') {
                $bookingUpdates['start_at'] = $startAt;
                $bookingUpdates['end_at'] = $endAt;
            }

            $lockedBooking->update($bookingUpdates);

            AuditLogService::log(
                $staff,
                'booking.checked_in',
                'Booking',
                $lockedBooking->booking_code,
                [
                    'booking_code' => $lockedBooking->booking_code,
                    'assigned_asset_id' => $targetAsset->id,
                    'assigned_asset_code' => $targetAsset->asset_code,
                    'was_floating' => $booking->asset_unit_id === null,
                    'end_time_mode_applied' => $endTimeModeApplied,
                ],
                null,
                request()?->ip(),
                $lockedBooking->affiliate_id
            );

            $fresh = $lockedBooking->fresh([
                'items.asset.model.category',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'affiliate',
            ]);
            $fresh->setAttribute('end_time_mode_applied', $endTimeModeApplied);
            if ($latePickupNotice !== null) {
                $fresh->setAttribute('late_pickup_notice', $latePickupNotice);
            }

            return $fresh;
        });
    }

    /**
     * Transition a booked/late_pickup reservation to `no_show`, stamp `no_show_at`,
     * record DP disposition (`forfeited` or `refunded`), and release pinned units.
     */
    public function markNoShow(
        Booking $booking,
        ?User $actor = null,
        ?string $reason = null,
        bool $isAutomated = false,
        ?string $dpDisposition = null,
        ?string $managerPin = null,
        ?int $paymentMethodId = null
    ): Booking {
        return DB::transaction(function () use ($booking, $actor, $reason, $isAutomated, $dpDisposition, $managerPin, $paymentMethodId) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['payments', 'items.asset', 'assetUnit', 'customer', 'assetModel', 'affiliate'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover', 'late_pickup'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$lockedBooking->booking_code} tidak dapat ditandai No-Show (status saat ini: {$lockedBooking->status})."],
                ]);
            }

            // Release any reserved physical units pinned to this booking
            $reservedAssetIds = collect();
            if ($lockedBooking->asset_unit_id) {
                $reservedAssetIds->push((int) $lockedBooking->asset_unit_id);
            }
            foreach ($lockedBooking->items as $item) {
                if ($item->asset_id) {
                    $reservedAssetIds->push((int) $item->asset_id);
                }
            }
            if ($reservedAssetIds->isNotEmpty()) {
                Asset::withoutGlobalScopes()
                    ->whereIn('id', $reservedAssetIds->unique()->all())
                    ->where('status', 'reserved')
                    ->lockForUpdate()
                    ->update(['status' => 'ready']);
            }

            $paidAmount = (float) $lockedBooking->paid_amount;
            $resolvedDisposition = $paidAmount > 0
                ? ($dpDisposition === 'refunded' ? 'refunded' : 'forfeited')
                : null;
            $forfeitedDp = ($resolvedDisposition === 'forfeited') ? $paidAmount : 0.0;

            if ($resolvedDisposition === 'refunded' && $paidAmount > 0 && $actor) {
                if (!empty($managerPin)) {
                    ManagerPinVerificationService::verify(
                        $actor,
                        $managerPin,
                        $lockedBooking->business_id ? (int) $lockedBooking->business_id : null,
                        (int) $lockedBooking->affiliate_id,
                        'Pengembalian DP saat No-Show memerlukan otorisasi PIN Manager.',
                        request()?->ip(),
                        'BookingNoShowRefund'
                    );
                }

                $activeShift = ShiftEnforcementService::getActiveShift((int) $lockedBooking->affiliate_id, $actor);
                $resolvedMethodId = $paymentMethodId
                    ?? $lockedBooking->payments()->where('direction', 'inflow')->latest('id')->value('payment_method_id')
                    ?? PaymentMethod::where('code', 'CASH')->value('id')
                    ?? PaymentMethod::value('id')
                    ?? 1;

                $datePrefix = Carbon::now()->format('ymd');
                $countToday = PaymentTransaction::withoutGlobalScopes()
                    ->where('business_id', $lockedBooking->business_id)
                    ->whereDate('transacted_at', Carbon::today())
                    ->count() + 1;
                $txCode = sprintf('TX-REF-%s-%04d-%s', $datePrefix, $countToday, strtoupper(Str::random(3)));
                $receiptNumber = sprintf('REC-REF-%s-%s-%s', $lockedBooking->affiliate->code ?? 'PST', $datePrefix, strtoupper(Str::random(4)));
                $latestInflowId = $lockedBooking->payments()->where('direction', 'inflow')->latest('id')->value('id');

                PaymentTransaction::create([
                    'business_id' => $lockedBooking->business_id,
                    'branch_id' => $lockedBooking->affiliate_id,
                    'shift_id' => $activeShift?->id,
                    'status' => 'completed',
                    'transaction_code' => $txCode,
                    'receipt_number' => $receiptNumber,
                    'booking_id' => $lockedBooking->id,
                    'payment_method_id' => $resolvedMethodId,
                    'processed_by' => $actor->id,
                    'reversal_of_id' => $latestInflowId,
                    'type' => 'refund',
                    'direction' => 'outflow',
                    'amount' => $paidAmount,
                    'cash_received' => 0.00,
                    'cash_change' => 0.00,
                    'notes' => $reason
                        ? "Refund DP No-Show ({$reason})"
                        : 'Refund DP No-Show',
                    'transacted_at' => Carbon::now(),
                ]);
            }

            $lockedBooking->update([
                'status' => 'no_show',
                'no_show_at' => Carbon::now(),
                'forfeited_dp_amount' => $forfeitedDp,
                'dp_disposition' => $resolvedDisposition,
                'no_show_reason' => $reason,
                'is_overdue_pickup' => false,
                'is_late_pickup' => false,
                'notes' => $reason
                    ? trim(($lockedBooking->notes ? $lockedBooking->notes . "\n" : '') . "[No-Show] {$reason}")
                    : $lockedBooking->notes,
            ]);

            if ($resolvedDisposition === 'refunded') {
                $lockedBooking->recalculateFinancials(true);
            }

            AuditLogService::log(
                $actor,
                $isAutomated ? 'booking.auto_noshow' : 'booking.marked_no_show',
                'Booking',
                $lockedBooking->booking_code,
                [
                    'booking_code' => $lockedBooking->booking_code,
                    'forfeited_dp_amount' => $forfeitedDp,
                    'dp_disposition' => $resolvedDisposition,
                    'reason' => $reason,
                    'automated' => $isAutomated,
                ],
                null,
                request()?->ip(),
                $lockedBooking->affiliate_id,
                $lockedBooking->business_id
            );

            return $lockedBooking->fresh([
                'items.asset.model',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'affiliate',
            ]);
        });
    }

    /**
     * Transition an uncollected booking past its full end_at window to `expired` and release stock.
     */
    public function expireUncollectedBooking(Booking $booking, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['items.asset', 'assetUnit', 'affiliate'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover', 'late_pickup'], true)) {
                return $lockedBooking;
            }

            $reservedAssetIds = collect();
            if ($lockedBooking->asset_unit_id) {
                $reservedAssetIds->push((int) $lockedBooking->asset_unit_id);
            }
            foreach ($lockedBooking->items as $item) {
                if ($item->asset_id) {
                    $reservedAssetIds->push((int) $item->asset_id);
                }
            }
            if ($reservedAssetIds->isNotEmpty()) {
                Asset::withoutGlobalScopes()
                    ->whereIn('id', $reservedAssetIds->unique()->all())
                    ->where('status', 'reserved')
                    ->lockForUpdate()
                    ->update(['status' => 'ready']);
            }

            $sysReason = $reason ?? 'Otomatis kedaluwarsa karena melewati batas akhir jadwal sewa.';
            $lockedBooking->update([
                'status' => 'expired',
                'no_show_at' => $lockedBooking->no_show_at ?? Carbon::now(),
                'no_show_reason' => $sysReason,
                'is_overdue_pickup' => false,
                'is_late_pickup' => false,
                'notes' => trim(($lockedBooking->notes ? $lockedBooking->notes . "\n" : '') . "[Expired] {$sysReason}"),
            ]);

            AuditLogService::log(
                null,
                'booking.auto_expired',
                'Booking',
                $lockedBooking->booking_code,
                [
                    'booking_code' => $lockedBooking->booking_code,
                    'reason' => $sysReason,
                ],
                null,
                null,
                $lockedBooking->affiliate_id,
                $lockedBooking->business_id
            );

            return $lockedBooking->fresh([
                'items.asset.model',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'affiliate',
            ]);
        });
    }

    /**
     * Cancel a scheduled reservation (`booked` -> `cancelled`), release reserved inventory,
     * and optionally refund any paid DP with Manager PIN verification.
     */
    public function cancelBooking(
        Booking $booking,
        User $actor,
        ?string $reason = null,
        bool $refundDp = false,
        ?string $managerPin = null,
        ?int $paymentMethodId = null
    ): Booking {
        return DB::transaction(function () use ($booking, $actor, $reason, $refundDp, $managerPin, $paymentMethodId) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['payments', 'items.asset', 'assetUnit', 'customer', 'assetModel', 'affiliate'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover', 'late_pickup'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$lockedBooking->booking_code} tidak dapat dibatalkan (status saat ini: {$lockedBooking->status})."],
                ]);
            }

            // Release any reserved physical unit pinned to this booking
            $reservedAssetIds = collect();
            if ($lockedBooking->asset_unit_id) {
                $reservedAssetIds->push((int) $lockedBooking->asset_unit_id);
            }
            foreach ($lockedBooking->items as $item) {
                if ($item->asset_id) {
                    $reservedAssetIds->push((int) $item->asset_id);
                }
            }
            if ($reservedAssetIds->isNotEmpty()) {
                Asset::withoutGlobalScopes()
                    ->whereIn('id', $reservedAssetIds->unique()->all())
                    ->where('status', 'reserved')
                    ->lockForUpdate()
                    ->update(['status' => 'ready']);
            }

            // Optional DP refund (requires Manager PIN + active shift)
            $lockedBooking->recalculateFinancials(false);
            $paidAmount = (float) $lockedBooking->paid_amount;

            if ($refundDp && $paidAmount > 0) {
                $approver = ManagerPinVerificationService::verify(
                    $actor,
                    $managerPin,
                    $lockedBooking->business_id ? (int) $lockedBooking->business_id : null,
                    (int) $lockedBooking->affiliate_id,
                    'Pengembalian DP saat pembatalan booking memerlukan otorisasi PIN Manager.',
                    request()?->ip(),
                    'BookingCancel'
                );

                $activeShift = ShiftEnforcementService::requireActiveShift(
                    (int) $lockedBooking->affiliate_id,
                    $actor,
                    true,
                    'mengeluarkan pengembalian dana DP pembatalan booking dari laci kasir'
                );

                $resolvedMethodId = $paymentMethodId
                    ?? $lockedBooking->payments()->where('direction', 'inflow')->latest('id')->value('payment_method_id')
                    ?? PaymentMethod::where('code', 'CASH')->value('id')
                    ?? PaymentMethod::value('id')
                    ?? 1;

                $datePrefix = Carbon::now()->format('ymd');
                $countToday = PaymentTransaction::withoutGlobalScopes()
                    ->where('business_id', $lockedBooking->business_id)
                    ->whereDate('transacted_at', Carbon::today())
                    ->count() + 1;
                $txCode = sprintf('TX-REF-%s-%04d-%s', $datePrefix, $countToday, strtoupper(Str::random(3)));
                $receiptNumber = sprintf('REC-REF-%s-%s-%s', $lockedBooking->affiliate->code ?? 'PST', $datePrefix, strtoupper(Str::random(4)));
                $latestInflowId = $lockedBooking->payments()->where('direction', 'inflow')->latest('id')->value('id');

                PaymentTransaction::create([
                    'business_id' => $lockedBooking->business_id,
                    'branch_id' => $lockedBooking->affiliate_id,
                    'shift_id' => $activeShift?->id,
                    'status' => 'completed',
                    'transaction_code' => $txCode,
                    'receipt_number' => $receiptNumber,
                    'booking_id' => $lockedBooking->id,
                    'payment_method_id' => $resolvedMethodId,
                    'processed_by' => $actor->id,
                    'reversal_of_id' => $latestInflowId,
                    'type' => 'refund',
                    'direction' => 'outflow',
                    'amount' => $paidAmount,
                    'cash_received' => 0.00,
                    'cash_change' => 0.00,
                    'notes' => $reason
                        ? "Refund pembatalan booking ({$reason}) [Approved by: {$approver->name}]"
                        : "Refund pembatalan booking [Approved by: {$approver->name}]",
                    'transacted_at' => Carbon::now(),
                ]);
            }

            $lockedBooking->status = 'cancelled';
            $lockedBooking->is_overdue_pickup = false;
            $lockedBooking->is_late_pickup = false;
            if ($reason) {
                $lockedBooking->notes = trim(($lockedBooking->notes ? $lockedBooking->notes . "\n" : '') . "[Dibatalkan] {$reason}");
            }
            $lockedBooking->recalculateFinancials();

            AuditLogService::log(
                $actor,
                'booking.cancelled',
                'Booking',
                $lockedBooking->booking_code,
                [
                    'booking_code' => $lockedBooking->booking_code,
                    'refund_dp' => $refundDp,
                    'refunded_amount' => $refundDp ? $paidAmount : 0.0,
                    'reason' => $reason,
                ],
                null,
                request()?->ip(),
                $lockedBooking->affiliate_id
            );

            return $lockedBooking->fresh([
                'items.asset.model',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'affiliate',
            ]);
        });
    }

    /**
     * Extend the pickup grace period for an active/overdue scheduled booking.
     */
    public function extendGracePeriod(
        Booking $booking,
        User $actor,
        ?int $additionalMinutes = null,
        ?Carbon $newGraceExpiresAt = null
    ): Booking {
        return DB::transaction(function () use ($booking, $actor, $additionalMinutes, $newGraceExpiresAt) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'late_pickup'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Batas ambil hanya dapat diperpanjang untuk booking yang belum check-in."],
                ]);
            }

            $now = Carbon::now();
            $currentGraceRaw = $lockedBooking->grace_extended_until ?? $lockedBooking->grace_expires_at;
            $currentGrace = $currentGraceRaw ? Carbon::parse($currentGraceRaw) : null;
            $base = ($currentGrace && $currentGrace->gt($now)) ? $currentGrace->copy() : $now->copy();
            $targetGrace = $newGraceExpiresAt ?? $base->addMinutes(max(5, $additionalMinutes ?? 30));

            $extendUpdates = [
                'grace_expires_at' => $targetGrace,
                'grace_extended_until' => $targetGrace,
                'is_overdue_pickup' => false,
                'is_late_pickup' => false,
            ];
            if ($lockedBooking->status === 'late_pickup') {
                $extendUpdates['status'] = 'booked';
            }

            $lockedBooking->update($extendUpdates);

            AuditLogService::log(
                $actor,
                'booking.grace_extended',
                'Booking',
                $lockedBooking->booking_code,
                [
                    'booking_code' => $lockedBooking->booking_code,
                    'previous_grace_expires_at' => $currentGrace?->toIso8601String(),
                    'new_grace_expires_at' => $targetGrace->toIso8601String(),
                ],
                null,
                request()?->ip(),
                $lockedBooking->affiliate_id
            );

            return $lockedBooking->fresh([
                'items.asset.model',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'affiliate',
            ]);
        });
    }

    /**
     * Reschedule a booked reservation to a new start timestamp with conflict-safe availability checks.
     */
    public function rescheduleBooking(
        Booking $booking,
        Carbon $newStartAt,
        bool $keepOriginalEndAt = false,
        ?User $actor = null
    ): Booking {
        return DB::transaction(function () use ($booking, $newStartAt, $keepOriginalEndAt, $actor) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['items.asset.model', 'items.assetModel', 'assetModel', 'assetUnit', 'affiliate', 'payments'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover', 'late_pickup'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$lockedBooking->booking_code} tidak dapat dijadwalkan ulang (status saat ini: {$lockedBooking->status})."],
                ]);
            }

            $shiftMeta = $this->shiftBookingSchedule($lockedBooking, $newStartAt, $keepOriginalEndAt, true);
            if ($lockedBooking->status === 'late_pickup' || $lockedBooking->is_late_pickup) {
                $lockedBooking->update([
                    'status' => 'booked',
                    'is_late_pickup' => false,
                ]);
            }

            if ($actor) {
                AuditLogService::log(
                    $actor,
                    'booking.rescheduled',
                    'Booking',
                    $lockedBooking->booking_code,
                    [
                        'booking_code' => $lockedBooking->booking_code,
                        'new_start_at' => $shiftMeta['new_start_at']->toIso8601String(),
                        'new_end_at' => $shiftMeta['new_end_at']->toIso8601String(),
                        'keep_original_end_at' => $keepOriginalEndAt,
                        'duration_hours' => $shiftMeta['duration_hours'],
                    ],
                    [
                        'previous_start_at' => $shiftMeta['previous_start_at']->toIso8601String(),
                        'previous_end_at' => $shiftMeta['previous_end_at']->toIso8601String(),
                    ],
                    request()?->ip(),
                    $lockedBooking->affiliate_id
                );
            }

            return $lockedBooking->fresh([
                'items.asset.model.category',
                'items.assetModel',
                'assetModel',
                'assetUnit',
                'customer',
                'payments.paymentMethod',
                'affiliate',
            ]);
        });
    }

    /**
     * Apply early-pickup start-time shift when handing over a scheduled booking ahead of `start_at`.
     */
    public function applyEarlyPickupShift(Booking $lockedBooking, bool $keepOriginalEndAt = false): Booking
    {
        $now = Carbon::now();
        if (Carbon::parse($lockedBooking->start_at)->gt($now)) {
            $this->shiftBookingSchedule($lockedBooking, $now, $keepOriginalEndAt, true);
            $lockedBooking->refresh();
        }

        return $lockedBooking;
    }

    /**
     * Validate availability (excluding this booking) and persist shifted schedule & optional price delta.
     *
     * @return array{previous_start_at: Carbon, previous_end_at: Carbon, new_start_at: Carbon, new_end_at: Carbon, duration_hours: int}
     */
    protected function shiftBookingSchedule(
        Booking $lockedBooking,
        Carbon $newStartAt,
        bool $keepOriginalEndAt = false,
        bool $clearGraceAndNoShow = true
    ): array {
        $previousStartAt = Carbon::parse($lockedBooking->start_at);
        $previousEndAt = Carbon::parse($lockedBooking->end_at);

        if ($keepOriginalEndAt) {
            $newEndAt = $previousEndAt->copy();
            if ($newStartAt->gte($newEndAt)) {
                throw ValidationException::withMessages([
                    'new_start_at' => ['Waktu mulai baru harus lebih awal dari batas kembali booking.'],
                ]);
            }
            $newDurationHours = max(1, (int) ceil($newStartAt->diffInMinutes($newEndAt) / 60));
        } else {
            $durationHours = max(
                1,
                (int) ($lockedBooking->duration_hours ?: max(1, $previousStartAt->diffInHours($previousEndAt)))
            );
            $newEndAt = $newStartAt->copy()->addHours($durationHours);
            $newDurationHours = $durationHours;
        }

        $branchId = (int) $lockedBooking->affiliate_id;
        $modelId = $lockedBooking->device_model_id;

        if ($branchId > 0) {
            Asset::withoutGlobalScopes()
                ->where('affiliate_id', $branchId)
                ->when($modelId, fn ($q) => $q->where('model_id', $modelId))
                ->lockForUpdate()
                ->get(['id']);
        }

        // 1. Validate pinned physical unit(s) if already assigned
        $pinnedAssets = collect();
        if ($lockedBooking->asset_unit_id) {
            $pinnedAsset = Asset::withoutGlobalScopes()->find((int) $lockedBooking->asset_unit_id);
            if ($pinnedAsset) {
                $pinnedAssets->push($pinnedAsset);
            }
        }
        foreach ($lockedBooking->items as $item) {
            if ($item->asset_id && $item->asset) {
                $pinnedAssets->push($item->asset);
            }
        }

        foreach ($pinnedAssets->unique('id') as $asset) {
            if (
                !in_array($asset->status, BookingAvailabilityService::RENTABLE_ASSET_STATUSES, true) ||
                !$this->pricingService->isAssetAvailable($asset, $newStartAt, $newEndAt, (int) $lockedBooking->id)
            ) {
                throw ValidationException::withMessages([
                    'new_start_at' => ['Unit/slot model penuh pada jadwal baru tersebut.'],
                ]);
            }
        }

        // 2. Validate branch-wide model capacity (excluding this booking's own ID)
        if ($branchId > 0 && $modelId) {
            $capacity = $this->availabilityService->getBranchModelCapacity(
                $branchId,
                (int) $modelId,
                $newStartAt,
                $newEndAt,
                (int) $lockedBooking->id
            );
            if ($capacity['available_slots'] < 1) {
                throw ValidationException::withMessages([
                    'new_start_at' => ['Unit/slot model penuh pada jadwal baru tersebut.'],
                ]);
            }
        }

        $updates = [
            'start_at' => $newStartAt,
            'end_at' => $newEndAt,
            'duration_hours' => $newDurationHours,
        ];

        // 3. Recalculate subtotal & balance when keeping original return date changed duration
        if ($keepOriginalEndAt && $newDurationHours !== (int) $lockedBooking->duration_hours) {
            $primaryItem = $lockedBooking->items
                ->first(fn ($item) => $item->item_type === 'primary' || $item->item_type === null);
            $targetModel = $lockedBooking->assetModel
                ?? $primaryItem?->assetModel
                ?? $primaryItem?->asset?->model;

            if ($targetModel) {
                $newPrimarySubtotal = $this->pricingService->calculateItemPriceForDuration($targetModel, $newDurationHours);
                $newDurationUnit = ($newDurationHours % 24 === 0) ? 'day' : 'hour';
                $newDurationValue = ($newDurationHours % 24 === 0) ? intdiv($newDurationHours, 24) : $newDurationHours;

                if ($primaryItem) {
                    $primaryItem->update([
                        'duration_unit' => $newDurationUnit,
                        'duration_value' => $newDurationValue,
                        'unit_price' => $newPrimarySubtotal,
                    ]);
                }

                $addonSubtotal = (float) ($lockedBooking->addon_subtotal ?? 0.0);
                $newSubtotal = (float) $newPrimarySubtotal + $addonSubtotal;
                $newTotalBill = max(0.0, $newSubtotal - (float) ($lockedBooking->discount_amount ?? 0.0) + (float) ($lockedBooking->deposit_fee ?? 0.0));

                $inflowPaid = (float) $lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange', 'deposit'])
                    ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'void'))
                    ->sum('amount');
                $outflowRefunds = (float) $lockedBooking->payments()
                    ->where('type', 'refund')
                    ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'void'))
                    ->sum('amount');
                $netPaid = max(0.0, $inflowPaid - $outflowRefunds);

                $newRemainingBalance = max(0.0, round($newTotalBill - $netPaid, 2));
                $newPaymentStatus = ($netPaid >= $newTotalBill && $newTotalBill > 0)
                    ? 'paid'
                    : ($netPaid > 0 ? 'partial' : ($newTotalBill <= 0 ? 'paid' : 'unpaid'));

                $updates['duration_unit'] = $newDurationUnit;
                $updates['duration_value'] = $newDurationValue;
                $updates['primary_subtotal'] = $newPrimarySubtotal;
                $updates['subtotal_amount'] = $newSubtotal;
                $updates['total_bill'] = $newTotalBill;
                $updates['remaining_balance'] = $newRemainingBalance;
                $updates['payment_status'] = $newPaymentStatus;
            }
        }

        if ($clearGraceAndNoShow) {
            $branch = $lockedBooking->affiliate ?? Affiliate::withoutGlobalScopes()->find($branchId);
            $updates['grace_expires_at'] = $branch
                ? $this->availabilityService->computeGraceExpiresAt($newStartAt, $branch)
                : $newStartAt->copy()->addMinutes(60);
            $updates['no_show_at'] = null;
            $updates['is_overdue_pickup'] = false;
        }

        $lockedBooking->update($updates);

        return [
            'previous_start_at' => $previousStartAt,
            'previous_end_at' => $previousEndAt,
            'new_start_at' => $newStartAt,
            'new_end_at' => $newEndAt,
            'duration_hours' => $newDurationHours,
        ];
    }

    /**
     * Shared branch resolution and authorization for booking creation.
     *
     * @return array{0: Affiliate, 1: int|null}
     */
    protected function resolveAuthorizedBranchAndBusiness(array $data, User $cashier): array
    {
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        if (!empty($data['all_branches']) || in_array((string) ($data['branch_id'] ?? $data['affiliate_id'] ?? ''), ['all', '-1'], true)) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang aktif wajib dipilih untuk membuat transaksi sewa.'],
            ]);
        }

        if (!empty($data['branch_id']) && !empty($data['affiliate_id']) && (int) $data['branch_id'] !== (int) $data['affiliate_id']) {
            throw ValidationException::withMessages([
                'branch_id' => ['Parameter branch_id dan affiliate_id tidak sesuai.'],
            ]);
        }

        $affiliateId = !empty($data['branch_id'])
            ? (int) $data['branch_id']
            : (!empty($data['affiliate_id']) ? (int) $data['affiliate_id'] : null);
        if (empty($affiliateId)) {
            $affiliateId = $tenantContext->getBranchId();
        }
        if (empty($affiliateId) && !empty($cashier->affiliate_id)) {
            $affiliateId = $cashier->affiliate_id;
        }

        if (empty($affiliateId)) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang aktif wajib dipilih untuk membuat transaksi sewa.'],
            ]);
        }

        $affiliate = Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('id', $affiliateId)
            ->where('is_active', true)
            ->first();
        if (!$affiliate) {
            throw ValidationException::withMessages([
                'branch_id' => ['Cabang yang dipilih tidak ditemukan atau tidak aktif.'],
            ]);
        }

        if (!$businessId && $affiliate->business_id) {
            $businessId = $affiliate->business_id;
        }

        if ($businessId && $affiliate->business_id && (int) $affiliate->business_id !== (int) $businessId) {
            throw ValidationException::withMessages([
                'affiliate_id' => ['Cabang tidak valid untuk bisnis rental ini.'],
            ]);
        }

        if ($affiliate->business_id && !$cashier->isSuperAdmin()) {
            $isMember = BusinessMember::where('business_id', $affiliate->business_id)
                ->where('user_id', $cashier->id)
                ->where('is_active', true)
                ->exists();

            if (!$isMember && (int) $cashier->affiliate_id !== (int) $affiliate->id) {
                throw ValidationException::withMessages([
                    'affiliate_id' => ['Anda tidak memiliki akses ke cabang bisnis ini.'],
                ]);
            }
        }

        if (!$cashier->isSuperAdmin()) {
            if (!empty($cashier->affiliate_id) && (int) $cashier->affiliate_id !== (int) $affiliate->id) {
                throw ValidationException::withMessages([
                    'affiliate_id' => ['Akses cabang ditolak. Anda tidak berwenang untuk cabang ini.'],
                ]);
            }

            $member = $tenantContext->getMember() ?? BusinessMember::where('business_id', $businessId)
                ->where('user_id', $cashier->id)
                ->where('is_active', true)
                ->first();

            if ($member && $member->isStaff()) {
                $allowedBranches = $member->branchMembers()->pluck('affiliate_id')->toArray();
                if (!empty($allowedBranches) && !in_array((int) $affiliate->id, $allowedBranches)) {
                    throw ValidationException::withMessages([
                        'affiliate_id' => ['Akses cabang ditolak. Anda tidak ditugaskan di cabang ini.'],
                    ]);
                }
            }
        }

        return [$affiliate, $businessId ? (int) $businessId : null];
    }

    /**
     * Shared customer resolution, blacklist guard, and identity document persistence.
     */
    protected function resolveOrCreateCustomer(array $data, ?int $businessId): Customer
    {
        $customer = null;
        if (!empty($data['customer_id'])) {
            $customerQuery = Customer::query();
            if ($businessId) {
                $customerQuery->where('business_id', $businessId);
            }
            $customer = $customerQuery->find($data['customer_id']);
        }

        if (!$customer) {
            $customerAttrs = ['phone' => $data['customer_phone']];
            if ($businessId) {
                $customerAttrs['business_id'] = $businessId;
            }

            $customer = Customer::firstOrCreate(
                $customerAttrs,
                [
                    'name' => $data['customer_name'],
                    'email' => $data['customer_email'] ?? null,
                    'address' => $data['customer_address'] ?? null,
                ]
            );
        }

        $updates = [];
        if (!empty($data['customer_name']) && $customer->name !== $data['customer_name']) {
            $updates['name'] = $data['customer_name'];
        }
        if (!empty($data['customer_phone']) && $customer->phone !== $data['customer_phone']) {
            $updates['phone'] = $data['customer_phone'];
        }
        if (!empty($data['customer_address']) && $customer->address !== $data['customer_address']) {
            $updates['address'] = $data['customer_address'];
        }
        if (!empty($updates)) {
            $customer->update($updates);
        }

        if ($customer->is_blacklisted) {
            throw ValidationException::withMessages([
                'customer' => ['Pelanggan ini masuk dalam daftar hitam (Blacklist): ' . $customer->blacklist_reason],
            ]);
        }

        $identityFilePath = null;
        if (!empty($data['identity_photo_base64']) || !empty($data['identity_photo'])) {
            $photoData = $data['identity_photo_base64'] ?? $data['identity_photo'];
            if (is_string($photoData)) {
                $ext = 'jpg';
                if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $type)) {
                    $photoData = substr($photoData, strpos($photoData, ',') + 1);
                    $ext = strtolower($type[1]);
                }
                $decoded = base64_decode($photoData);
                if ($decoded === false) {
                    $decoded = $photoData;
                }
                $filename = 'id_' . $customer->id . '_' . time() . '_' . Str::random(6) . '.' . $ext;
                $identityFilePath = 'customer_identities/' . $filename;
                Storage::disk('local')->put($identityFilePath, $decoded);
            }
        }

        if (!empty($data['identity_number'])) {
            CustomerIdentity::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'identity_type' => $data['identity_type'] ?? 'KTP',
                    'identity_number' => $data['identity_number'],
                ],
                array_filter([
                    'file_path' => $identityFilePath,
                    'is_verified' => true,
                ])
            );
        }

        return $customer;
    }

    /**
     * Server-side authoritative pricing resolver.
     * Ignores client-supplied `unit_prices` overrides unless a valid Manager PIN is verified.
     *
     * @return array{0: float, 1: array}
     */
    protected function resolveAuthoritativeItemPrice(
        ?AssetModel $model,
        string $durationUnit,
        int $durationValue,
        Carbon $startAt,
        int $durationHours,
        ?float $passedPrice,
        bool $isAddonItem,
        array $data,
        User $cashier,
        Affiliate $affiliate,
        ?int $businessId
    ): array {
        if ($model) {
            $calc = null;
            try {
                $calc = $this->pricingService->calculate($model, $durationUnit, $durationValue, $startAt);
            } catch (ValidationException $e) {
                $multiplier = !empty($data['duration_multiplier']) ? (int)$data['duration_multiplier'] : 1;
                if ($multiplier > 1 && $durationValue % $multiplier === 0) {
                    try {
                        $baseValue = intdiv($durationValue, $multiplier);
                        $baseCalc = $this->pricingService->calculate($model, $durationUnit, $baseValue, $startAt);
                        $calc = $baseCalc;
                        $calc['price'] = (float)$baseCalc['price'] * $multiplier;
                        $calc['rate_snapshot']['price'] = $calc['price'];
                        $calc['rate_snapshot']['duration_value'] = $durationValue;
                        $calc['rate_snapshot']['duration_hours'] = $durationHours;
                    } catch (ValidationException) {
                        $calc = null;
                    }
                }

                if ($calc === null) {
                    if ($passedPrice !== null && !empty($data['manager_pin'])) {
                        ManagerPinVerificationService::verify(
                            $cashier,
                            $data['manager_pin'],
                            $businessId,
                            (int)$affiliate->id,
                            'Override harga manual memerlukan otorisasi PIN Manager.',
                            request()?->ip(),
                            'BookingPricing'
                        );
                        return [
                            $passedPrice,
                            [
                                'tier_id' => null,
                                'duration_unit' => $durationUnit,
                                'duration_value' => $durationValue,
                                'duration_hours' => $durationHours,
                                'price' => $passedPrice,
                                'extension_hourly_rate' => $isAddonItem ? 5000.00 : 15000.00,
                                'model_id' => $model->id,
                                'model_name' => $model->name,
                                'brand' => $model->brand,
                                'category_name' => $model->category?->name,
                                'calculated_at' => Carbon::now()->toIso8601String(),
                            ],
                        ];
                    }

                    if ($isAddonItem) {
                        $firstTier = $model->pricingTiers()->where('is_active', true)->first();
                        $unitPrice = $firstTier ? (float)$firstTier->price : 20000.00;
                        return [
                            $unitPrice,
                            [
                                'tier_id' => $firstTier?->id,
                                'duration_unit' => $durationUnit,
                                'duration_value' => $durationValue,
                                'duration_hours' => $durationHours,
                                'price' => $unitPrice,
                                'extension_hourly_rate' => $firstTier ? (float)$firstTier->extension_hourly_rate : 5000.00,
                                'model_id' => $model->id,
                                'model_name' => $model->name,
                                'brand' => $model->brand,
                                'category_name' => $model->category?->name,
                                'calculated_at' => Carbon::now()->toIso8601String(),
                            ],
                        ];
                    }

                    throw $e;
                }
            }

            $serverPrice = (float)$calc['price'];
            $finalPrice = $serverPrice;
            if ($passedPrice !== null && abs($passedPrice - $serverPrice) > 0.01 && !empty($data['manager_pin'])) {
                ManagerPinVerificationService::verify(
                    $cashier,
                    $data['manager_pin'],
                    $businessId,
                    (int)$affiliate->id,
                    'Override harga sewa memerlukan otorisasi PIN Manager.',
                    request()?->ip(),
                    'BookingPricing'
                );
                $finalPrice = $passedPrice;
            }

            return [$finalPrice, $calc['rate_snapshot']];
        }

        return [20000.00, ['price' => 20000.00]];
    }

    /**
     * Shared voucher/manual discount calculation & manager PIN verification.
     *
     * @return array{0: string, 1: float, 2: int|null, 3: string|null}
     */
    protected function resolveDiscountAndApprover(
        array $data,
        float $subtotal,
        Customer $customer,
        Affiliate $affiliate,
        ?int $businessId,
        User $cashier
    ): array {
        $discountType = 'none';
        $discountAmount = 0.00;
        $voucherId = null;
        $approvedBy = null;
        $affiliateId = (int) $affiliate->id;

        if (!empty($data['voucher_code'])) {
            $voucherResult = $this->voucherService->validateVoucher(
                $data['voucher_code'],
                $subtotal,
                $customer->id,
                $affiliateId
            );

            $discountType = 'voucher';
            $discountAmount = (float) $voucherResult['calculated_discount'];
            $voucherId = $voucherResult['voucher_id'];
        } elseif (!empty($data['manual_discount_amount']) && (float) $data['manual_discount_amount'] > 0) {
            $discountType = 'manual';
            $discountAmount = (float) $data['manual_discount_amount'];

            $discountPercent = $subtotal > 0 ? ($discountAmount / $subtotal) * 100 : 100;
            $maxPercent = (float) $affiliate->cashier_discount_limit_percent;
            $maxNominal = (float) $affiliate->cashier_discount_limit_nominal;

            if ($discountPercent > $maxPercent || $discountAmount > $maxNominal) {
                $matchingManager = ManagerPinVerificationService::verify(
                    $cashier,
                    $data['manager_pin'] ?? null,
                    $businessId,
                    $affiliateId,
                    'Diskon manual melebihi batas wewenang kasir (Max: ' . $maxPercent . '% / Rp ' . number_format($maxNominal, 0, ',', '.') . '). Diperlukan PIN Manager.',
                    request()?->ip(),
                    'BookingDiscount'
                );
                $approvedBy = $matchingManager->id;
            } else {
                $approvedBy = $cashier->id;
            }
        }

        return [$discountType, $discountAmount, $voucherId, $approvedBy];
    }

    /**
     * Record initial payment ledger entry (`dp` or `settlement`) for a booking.
     */
    protected function recordBookingPayment(
        Booking $booking,
        Affiliate $affiliate,
        User $cashier,
        int $paymentMethodId,
        float $amount,
        ?float $cashReceivedInput,
        string $txType,
        ?int $shiftId,
        string $notes,
        ?string $idempotencyKey = null,
        ?string $externalReference = null
    ): PaymentTransaction {
        $paymentMethod = PaymentMethod::findOrFail($paymentMethodId);
        $isCash = strtoupper($paymentMethod->code) === 'CASH' || stripos($paymentMethod->name, 'tunai') !== false;
        $businessId = $booking->business_id;

        $business = $businessId ? Business::find($businessId) : null;
        if ($business && !$business->isPaymentMethodEnabled($paymentMethod, (int) $affiliate->id)) {
            throw ValidationException::withMessages([
                'payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
            ]);
        }

        $activeShift = null;
        if ($shiftId) {
            $activeShift = \App\Models\CashDrawerShift::where('id', $shiftId)
                ->where('branch_id', (int) $affiliate->id)
                ->where('status', 'open')
                ->first();
        }
        $activeShift = $activeShift ?? ShiftEnforcementService::requireActiveShift(
            (int) $affiliate->id,
            $cashier,
            $isCash,
            'memproses transaksi'
        );

        if ($isCash && $cashReceivedInput !== null && $cashReceivedInput < $amount) {
            throw ValidationException::withMessages([
                'cash_received' => ['Jumlah uang tunai yang diterima kurang dari nominal pembayaran.'],
            ]);
        }

        $cashReceived = $isCash ? ($cashReceivedInput ?? $amount) : $amount;
        $cashChange = $isCash ? max(0, $cashReceived - $amount) : 0.00;
        $recordedDeposit = (float) $booking->payments()
            ->where('type', 'deposit')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'void'))
            ->sum('amount');
        $remainingDeposit = ($txType === 'settlement')
            ? max(0.0, round((float) ($booking->deposit_fee ?? 0.0) - $recordedDeposit, 2))
            : 0.0;

        return $this->recordRentalAndDepositTransactions(
            $booking,
            $affiliate,
            $cashier,
            $paymentMethod,
            $isCash,
            $amount,
            $remainingDeposit,
            $cashReceived,
            $cashChange,
            $txType,
            $activeShift?->id,
            $notes,
            $idempotencyKey,
            $externalReference
        );
    }

    /**
     * Split rental revenue and refundable security deposit (`type = 'deposit'`) into distinct ledger entries.
     */
    protected function recordRentalAndDepositTransactions(
        Booking $booking,
        Affiliate $affiliate,
        User $cashier,
        PaymentMethod $paymentMethod,
        bool $isCash,
        float $totalCollected,
        float $depositPortion,
        float $cashReceived,
        float $cashChange,
        string $primaryType,
        ?int $shiftId,
        string $notes,
        ?string $idempotencyKey = null,
        ?string $externalReference = null
    ): PaymentTransaction {
        $businessId = $booking->business_id;
        $datePrefix = Carbon::now()->format('ymd');
        $depositAmount = min($totalCollected, max(0.0, round($depositPortion, 2)));
        $rentalAmount = max(0.0, round($totalCollected - $depositAmount, 2));

        $primaryTx = null;

        if ($rentalAmount > 0) {
            $txCount = (int) PaymentTransaction::where('business_id', $businessId)->count() + 1;
            $primaryTx = PaymentTransaction::create([
                'business_id' => $businessId,
                'branch_id' => $affiliate->id,
                'shift_id' => $shiftId,
                'transaction_code' => sprintf('TX-%s-%04d-%s', $datePrefix, $txCount, strtoupper(Str::random(4))),
                'receipt_number' => sprintf('REC-%s-%s-%s', $affiliate->code ?? 'CTR', $datePrefix, strtoupper(Str::random(6))),
                'external_reference' => $externalReference,
                'idempotency_key' => $idempotencyKey,
                'booking_id' => $booking->id,
                'payment_method_id' => $paymentMethod->id,
                'processed_by' => $cashier->id,
                'type' => $primaryType,
                'status' => 'completed',
                'direction' => 'inflow',
                'amount' => $rentalAmount,
                'cash_received' => $isCash ? max($rentalAmount, $cashReceived - $depositAmount) : $rentalAmount,
                'cash_change' => $cashChange,
                'notes' => $notes,
                'transacted_at' => Carbon::now(),
            ]);
        }

        if ($depositAmount > 0) {
            $txCount = (int) PaymentTransaction::where('business_id', $businessId)->count() + 1;
            $depositTx = PaymentTransaction::create([
                'business_id' => $businessId,
                'branch_id' => $affiliate->id,
                'shift_id' => $shiftId,
                'transaction_code' => sprintf('TX-DEP-%s-%04d-%s', $datePrefix, $txCount, strtoupper(Str::random(4))),
                'receipt_number' => sprintf('REC-DEP-%s-%s-%s', $affiliate->code ?? 'CTR', $datePrefix, strtoupper(Str::random(6))),
                'external_reference' => $externalReference,
                'idempotency_key' => $primaryTx ? null : $idempotencyKey,
                'booking_id' => $booking->id,
                'payment_method_id' => $paymentMethod->id,
                'processed_by' => $cashier->id,
                'type' => 'deposit',
                'status' => 'completed',
                'direction' => 'inflow',
                'amount' => $depositAmount,
                'cash_received' => $depositAmount,
                'cash_change' => 0.00,
                'notes' => 'Uang titipan jaminan sewa (deposit)',
                'transacted_at' => Carbon::now(),
            ]);
            $primaryTx ??= $depositTx;
        }

        if (!$primaryTx) {
            $txCount = (int) PaymentTransaction::where('business_id', $businessId)->count() + 1;
            $primaryTx = PaymentTransaction::create([
                'business_id' => $businessId,
                'branch_id' => $affiliate->id,
                'shift_id' => $shiftId,
                'transaction_code' => sprintf('TX-%s-%04d-%s', $datePrefix, $txCount, strtoupper(Str::random(4))),
                'receipt_number' => sprintf('REC-%s-%s-%s', $affiliate->code ?? 'CTR', $datePrefix, strtoupper(Str::random(6))),
                'external_reference' => $externalReference,
                'idempotency_key' => $idempotencyKey,
                'booking_id' => $booking->id,
                'payment_method_id' => $paymentMethod->id,
                'processed_by' => $cashier->id,
                'type' => $primaryType,
                'status' => 'completed',
                'direction' => 'inflow',
                'amount' => 0.00,
                'cash_received' => $cashReceived,
                'cash_change' => $cashChange,
                'notes' => $notes,
                'transacted_at' => Carbon::now(),
            ]);
        }

        return $primaryTx;
    }

    /**
     * Validate and resolve structured or legacy collateral fields against tenant settings.
     *
     * @return array{
     *   collateral_type: string,
     *   collateral_type_id: ?string,
     *   collateral_type_name: ?string,
     *   collateral_storage_slot: ?string,
     *   collateral_deposit_amount: ?float,
     *   collateral_secured: bool
     * }
     */
    protected function resolveCollateralData(
        ?Business $business,
        array $data,
        bool $requireDetail,
        ?Booking $existingBooking = null
    ): array {
        $enableCollateral = $business ? $business->isCollateralEnabled() : false;
        $rawTypeId = trim((string) ($data['collateral_type_id'] ?? $existingBooking?->collateral_type_id ?? ''));
        $rawSlotOrDetail = trim((string) ($data['collateral_detail'] ?? $data['collateral_storage_slot'] ?? $existingBooking?->collateral_storage_slot ?? ''));
        $rawDeposit = $data['collateral_deposit_amount'] ?? $existingBooking?->collateral_deposit_amount ?? null;
        $depositAmount = ($rawDeposit !== null && $rawDeposit !== '') ? (float) $rawDeposit : null;
        $rawLegacyType = trim((string) ($data['collateral_type_name'] ?? $data['collateral_type'] ?? $existingBooking?->collateral_type ?? ''));

        if (!$enableCollateral) {
            $hasExplicitLegacyInput = $rawSlotOrDetail !== '' || ($depositAmount !== null && $depositAmount > 0);
            if ($rawTypeId === '' && !$hasExplicitLegacyInput) {
                return [
                    'collateral_type' => null,
                    'collateral_type_id' => null,
                    'collateral_type_name' => null,
                    'collateral_storage_slot' => null,
                    'collateral_deposit_amount' => null,
                    'collateral_secured' => false,
                ];
            }
            if ($rawTypeId === '' && $hasExplicitLegacyInput) {
                $legacyName = $rawLegacyType !== '' ? $rawLegacyType : 'KTP Asli';
                return [
                    'collateral_type' => $legacyName,
                    'collateral_type_id' => null,
                    'collateral_type_name' => $legacyName,
                    'collateral_storage_slot' => $rawSlotOrDetail !== '' ? $rawSlotOrDetail : null,
                    'collateral_deposit_amount' => $depositAmount,
                    'collateral_secured' => $requireDetail,
                ];
            }
        }

        // Legacy callers omitting collateral_type_id while collateral feature is enabled keep fallback unless require_collateral=true with empty input
        if ($rawTypeId === '') {
            if ($enableCollateral && $business && !$business->isCollateralRequired() && $rawSlotOrDetail === '' && ($depositAmount === null || $depositAmount <= 0)) {
                return [
                    'collateral_type' => null,
                    'collateral_type_id' => null,
                    'collateral_type_name' => null,
                    'collateral_storage_slot' => null,
                    'collateral_deposit_amount' => null,
                    'collateral_secured' => false,
                ];
            }
            $legacyType = $rawLegacyType !== '' ? $rawLegacyType : 'KTP Asli';
            return [
                'collateral_type' => $legacyType,
                'collateral_type_id' => null,
                'collateral_type_name' => $legacyType,
                'collateral_storage_slot' => $rawSlotOrDetail !== '' ? $rawSlotOrDetail : ($requireDetail ? 'Brankas A, Slot 1' : null),
                'collateral_deposit_amount' => $depositAmount,
                'collateral_secured' => $requireDetail,
            ];
        }

        $requireCollateral = $business ? $business->isCollateralRequired() : false;

        if ($rawTypeId === 'none') {
            if ($enableCollateral && $requireCollateral) {
                throw ValidationException::withMessages([
                    'collateral_type_id' => ['Aturan toko mewajibkan jaminan saat sewa. Pilih tipe jaminan yang aktif.'],
                ]);
            }
            return [
                'collateral_type' => 'Tanpa Jaminan',
                'collateral_type_id' => 'none',
                'collateral_type_name' => 'Tanpa Jaminan',
                'collateral_storage_slot' => null,
                'collateral_deposit_amount' => null,
                'collateral_secured' => false,
            ];
        }

        $configured = $business ? $business->getResolvedCollateralTypes() : Business::DEFAULT_COLLATERAL_TYPES;
        $matched = collect($configured)->firstWhere('id', $rawTypeId);

        if (!$matched || empty($matched['is_active'])) {
            throw ValidationException::withMessages([
                'collateral_type_id' => ['Tipe jaminan tidak valid atau sudah dinonaktifkan oleh Owner.'],
            ]);
        }

        $mode = $matched['input_mode'];
        $name = $matched['name'];

        if ($mode === 'cash_deposit') {
            if ($requireDetail && ($depositAmount === null || $depositAmount <= 0)) {
                throw ValidationException::withMessages([
                    'collateral_deposit_amount' => ['Nominal deposit tunai wajib diisi lebih dari Rp 0.'],
                ]);
            }
            return [
                'collateral_type' => $name,
                'collateral_type_id' => $matched['id'],
                'collateral_type_name' => $name,
                'collateral_storage_slot' => $rawSlotOrDetail !== '' ? $rawSlotOrDetail : null,
                'collateral_deposit_amount' => $depositAmount,
                'collateral_secured' => $requireDetail,
            ];
        }

        // 'vault_slot' or 'note_only'
        if ($requireDetail && $rawSlotOrDetail === '') {
            $errorMsg = $mode === 'vault_slot'
                ? 'Slot brankas jaminan wajib diisi.'
                : 'Nomor / keterangan jaminan wajib diisi.';
            throw ValidationException::withMessages([
                'collateral_storage_slot' => [$errorMsg],
            ]);
        }

        return [
            'collateral_type' => $name,
            'collateral_type_id' => $matched['id'],
            'collateral_type_name' => $name,
            'collateral_storage_slot' => $rawSlotOrDetail !== '' ? $rawSlotOrDetail : null,
            'collateral_deposit_amount' => null,
            'collateral_secured' => $requireDetail,
        ];
    }
}
