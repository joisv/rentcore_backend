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
        protected BookingAvailabilityService $availabilityService
    ) {}

    /**
     * Create an atomic counter rental with immediate activation.
     * Collateral is recorded and locked into safe slot, assets locked to rented, payment ledger written.
     */
    public function createCounterRental(array $data, User $cashier): Booking
    {
        return DB::transaction(function () use ($data, $cashier) {
            $tenantContext = app(TenantContext::class);
            $businessId = $tenantContext->getBusinessId();

            // 1. Resolve affiliate_id safely without undefined array key
            $affiliateId = !empty($data['affiliate_id']) ? (int)$data['affiliate_id'] : null;

            // Fallback to TenantContext active branch (e.g. from X-Branch-Id header)
            if (empty($affiliateId)) {
                $affiliateId = $tenantContext->getBranchId();
            }

            // Fallback to cashier's explicit affiliate assignment (legacy role)
            if (empty($affiliateId) && !empty($cashier->affiliate_id)) {
                $affiliateId = $cashier->affiliate_id;
            }

            $rawPrimaryIds = (array)($data['asset_ids'] ?? []);
            $rawAddonIds = (array)($data['addon_asset_ids'] ?? []);
            $assetIds = array_values(array_unique(array_filter(array_merge($rawPrimaryIds, $rawAddonIds))));

            // Fallback to the business's central/default active branch
            if (empty($affiliateId) && $businessId) {
                $defaultBranch = Affiliate::where('business_id', $businessId)
                    ->where('is_active', true)
                    ->orderBy('is_central', 'desc')
                    ->first();
                $affiliateId = $defaultBranch?->id;
            }

            // Fail closed if affiliate still cannot be determined
            if (empty($affiliateId)) {
                throw ValidationException::withMessages([
                    'affiliate_id' => ['Cabang/outlet tidak dapat ditentukan. Harap tentukan cabang aktif.'],
                ]);
            }

            // Validate affiliate existence
            $affiliate = Affiliate::find($affiliateId);
            if (!$affiliate) {
                throw ValidationException::withMessages([
                    'affiliate_id' => ['Cabang yang dipilih tidak ditemukan.'],
                ]);
            }

            // Derive businessId from affiliate if not set in TenantContext
            if (!$businessId && $affiliate->business_id) {
                $businessId = $affiliate->business_id;
            }

            // Multi-tenant isolation: Verify branch belongs to active business
            if ($businessId && $affiliate->business_id && (int)$affiliate->business_id !== (int)$businessId) {
                throw ValidationException::withMessages([
                    'affiliate_id' => ['Cabang tidak valid untuk bisnis rental ini.'],
                ]);
            }

            // Authorize user access to this business
            if ($affiliate->business_id && !$cashier->isSuperAdmin()) {
                $isMember = BusinessMember::where('business_id', $affiliate->business_id)
                    ->where('user_id', $cashier->id)
                    ->where('is_active', true)
                    ->exists();

                if (!$isMember && (int)$cashier->affiliate_id !== (int)$affiliate->id) {
                    throw ValidationException::withMessages([
                        'affiliate_id' => ['Anda tidak memiliki akses ke cabang bisnis ini.'],
                    ]);
                }
            }

            // Authorize user access to this specific branch (if branch-restricted)
            if (!$cashier->isSuperAdmin()) {
                if (!empty($cashier->affiliate_id) && (int)$cashier->affiliate_id !== (int)$affiliate->id) {
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
                    if (!empty($allowedBranches) && !in_array((int)$affiliate->id, $allowedBranches)) {
                        throw ValidationException::withMessages([
                            'affiliate_id' => ['Akses cabang ditolak. Anda tidak ditugaskan di cabang ini.'],
                        ]);
                    }
                }
            }

            // Strict Pre-Checkout Shift Enforcement Check: Ensure Cash Drawer Shift is OPEN before anything is processed
            $activeShift = ShiftEnforcementService::requireActiveShift(
                (int)$affiliate->id,
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
                $assetQuery->where(function ($q) use ($businessId) {
                    $q->where('business_id', $businessId)
                      ->orWhereNull('business_id');
                });
            }

            $assets = $assetQuery->lockForUpdate()->get();

            if ($assets->count() !== count($assetIds)) {
                throw ValidationException::withMessages([
                    'asset_ids' => ['Sebagian perangkat yang dipilih tidak ditemukan di cabang ini atau telah dipindahkan ke cabang lain.'],
                ]);
            }

            foreach ($assets as $asset) {
                if ($asset->status !== 'ready') {
                    $modelName = $asset->model ? $asset->model->name : 'Unit Gadget';
                    throw ValidationException::withMessages([
                        'asset_ids' => ["Perangkat {$asset->asset_code} ({$modelName}) sedang tidak siap (status: {$asset->status})."],
                    ]);
                }
            }

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

            // 3. Duration & Time Calculation
            $durationUnit = $data['duration_unit'] ?? 'hour';
            $durationValue = (int)($data['duration_value'] ?? $data['duration_hours'] ?? 1);
            if (!empty($data['duration_hours']) && empty($data['duration_unit'])) {
                $durationUnit = 'hour';
                $durationValue = (int)$data['duration_hours'];
            }
            $durationHours = $this->pricingService->computeDurationHours($durationUnit, $durationValue);
            $startAt = !empty($data['start_at']) ? Carbon::parse($data['start_at'])->utc() : Carbon::now()->utc();
            $endAt = $this->pricingService->computeEndAt($startAt, $durationUnit, $durationValue);

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
                    $passedPrice = $data['unit_prices'][$asset->id] ?? $data['unit_prices'][(string)$asset->id] ?? null;
                }

                try {
                    $calc = $this->pricingService->calculate($asset->model, $durationUnit, $durationValue, $startAt);
                    $unitPrice = $passedPrice !== null ? (float)$passedPrice : $calc['price'];
                    $snapshot = $calc['rate_snapshot'];
                } catch (ValidationException $e) {
                    if ($passedPrice !== null) {
                        $unitPrice = (float)$passedPrice;
                        $snapshot = [
                            'tier_id' => null,
                            'duration_unit' => $durationUnit,
                            'duration_value' => $durationValue,
                            'duration_hours' => $durationHours,
                            'price' => $unitPrice,
                            'extension_hourly_rate' => 15000.00,
                            'model_id' => $asset->model->id,
                            'model_name' => $asset->model->name,
                            'brand' => $asset->model->brand,
                            'category_name' => $asset->model->category?->name,
                            'calculated_at' => Carbon::now()->toIso8601String(),
                        ];
                    } elseif ($isAddonItem) {
                        // Fallback for add-ons: use their first active tier or standard rate
                        $firstTier = $asset->model ? $asset->model->pricingTiers()->where('is_active', true)->first() : null;
                        $unitPrice = $firstTier ? (float)$firstTier->price : 20000.00;
                        $snapshot = [
                            'tier_id' => $firstTier?->id,
                            'duration_unit' => $durationUnit,
                            'duration_value' => $durationValue,
                            'duration_hours' => $durationHours,
                            'price' => $unitPrice,
                            'extension_hourly_rate' => $firstTier ? (float)$firstTier->extension_hourly_rate : 5000.00,
                            'model_id' => $asset->model?->id,
                            'model_name' => $asset->model?->name,
                            'brand' => $asset->model?->brand,
                            'category_name' => $asset->model?->category?->name,
                            'calculated_at' => Carbon::now()->toIso8601String(),
                        ];
                    } else {
                        throw $e;
                    }
                }

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
            $discountType = 'none';
            $discountAmount = 0.00;
            $voucherId = null;
            $approvedBy = null;

            if (!empty($data['voucher_code'])) {
                $voucherResult = $this->voucherService->validateVoucher(
                    $data['voucher_code'],
                    $subtotal,
                    $customer->id,
                    $affiliateId
                );

                $discountType = 'voucher';
                $discountAmount = $voucherResult['calculated_discount'];
                $voucherId = $voucherResult['voucher_id'];
            } elseif (!empty($data['manual_discount_amount']) && (float)$data['manual_discount_amount'] > 0) {
                $discountType = 'manual';
                $discountAmount = (float)$data['manual_discount_amount'];

                // Validate Cashier discount limits
                $discountPercent = ($discountAmount / $subtotal) * 100;
                $maxPercent = (float)$affiliate->cashier_discount_limit_percent;
                $maxNominal = (float)$affiliate->cashier_discount_limit_nominal;

                if ($discountPercent > $maxPercent || $discountAmount > $maxNominal) {
                    if (empty($data['manager_pin'])) {
                        throw ValidationException::withMessages([
                            'manager_pin' => ['Diskon manual melebihi batas wewenang kasir (Max: ' . $maxPercent . '% / Rp ' . number_format($maxNominal, 0, ',', '.') . '). Diperlukan PIN Manager.'],
                        ]);
                    }
                    // Validate Manager PIN
                    $managers = User::where(function ($q) use ($affiliateId, $businessId) {
                            $q->where('affiliate_id', $affiliateId);
                            if ($businessId) {
                                $q->orWhereHas('businessMembers', function ($bm) use ($businessId) {
                                    $bm->where('business_id', $businessId)->whereIn('role', ['owner', 'admin']);
                                });
                            }
                        })
                        ->whereNotNull('manager_override_pin')
                        ->get();

                    $matchingManager = $managers->first(function ($mgr) use ($data) {
                        return password_verify($data['manager_pin'], $mgr->manager_override_pin);
                    });

                    if (!$matchingManager) {
                        throw ValidationException::withMessages([
                            'manager_pin' => ['PIN Manager tidak valid.'],
                        ]);
                    }
                    $approvedBy = $matchingManager->id;
                } else {
                    $approvedBy = $cashier->id;
                }
            }

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

            // 7. Create Booking (Direct activation: status = 'rented')
            $booking = Booking::withoutGlobalScopes()->create([
                'business_id' => $businessId,
                'booking_code' => $bookingCode,
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
                'collateral_type' => $data['collateral_type'] ?? 'KTP Asli',
                'collateral_secured' => true,
                'collateral_storage_slot' => $data['collateral_storage_slot'] ?? 'Brankas A, Slot 1',
                'notes' => $data['notes'] ?? null,
            ]);

            // 8. Create Booking Items & transition assets to 'rented'
            foreach ($assets as $asset) {
                $itemType = in_array((int)$asset->id, $addonAssetIdsList, true) ? 'addon' : 'primary';
                BookingItem::create([
                    'booking_id' => $booking->id,
                    'asset_id' => $asset->id,
                    'asset_model_id' => $asset->model_id,
                    'item_type' => $itemType,
                    'unit_price' => $itemPrices[$asset->id],
                    'duration_unit' => $durationUnit,
                    'duration_value' => $durationValue,
                    'rate_snapshot' => $itemSnapshots[$asset->id] ?? null,
                ]);

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
            $paymentMethod = PaymentMethod::findOrFail($data['payment_method_id']);
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

            $txEntropy = strtoupper(Str::random(4));
            $txCount = (int)PaymentTransaction::where('business_id', $businessId)->count() + 1;
            $txCode = sprintf('TX-%s-%04d-%s', $datePrefix, $txCount, $txEntropy);
            $receiptNumber = sprintf('REC-%s-%s-%s', $affiliate->code ?? 'CTR', $datePrefix, strtoupper(Str::random(6)));

            PaymentTransaction::create([
                'business_id' => $businessId,
                'branch_id' => $affiliateId,
                'shift_id' => $activeShift?->id,
                'transaction_code' => $txCode,
                'receipt_number' => $receiptNumber,
                'booking_id' => $booking->id,
                'payment_method_id' => $paymentMethod->id,
                'processed_by' => $cashier->id,
                'type' => 'settlement',
                'status' => 'completed',
                'direction' => 'inflow',
                'amount' => $totalBill,
                'cash_received' => $cashReceived,
                'cash_change' => $cashChange,
                'notes' => 'Pembayaran lunas sewa langsung di counter kasir',
                'transacted_at' => Carbon::now(),
            ]);

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

            $rawPaidInput = $data['paid_amount'] ?? $data['dp_amount'] ?? null;
            $paidAmountRequested = $rawPaidInput !== null
                ? (float) $rawPaidInput
                : (!empty($data['payment_method_id']) ? null : 0.0);

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
                    ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                    ->findOrFail((int) $requestedModelId);

                // Serialize concurrent bookings at the branch level by locking branch assets of this model
                Asset::withoutGlobalScopes()
                    ->where('affiliate_id', $affiliateId)
                    ->where('model_id', $model->id)
                    ->lockForUpdate()
                    ->get(['id']);

                $capacity = $this->availabilityService->getBranchModelCapacity(
                    $affiliateId,
                    (int) $model->id,
                    $startAt,
                    $endAt
                );

                if ($capacity['available_slots'] < 1) {
                    throw ValidationException::withMessages([
                        'asset_model_id' => ["Slot model {$model->name} untuk jadwal yang dipilih sudah penuh (Tersedia: 0)."],
                    ]);
                }

                $primaryAssets = collect();
                $primaryModelId = (int) $model->id;
                $primaryUnitId = null;

                $passedModelPrice = $data['unit_prices']['model_' . $model->id]
                    ?? $data['unit_prices']['primary']
                    ?? null;
                $calc = null;
                try {
                    $calc = $this->pricingService->calculate($model, $durationUnit, $durationValue, $startAt);
                    $primaryPrice = $passedModelPrice !== null ? (float) $passedModelPrice : (float) $calc['price'];
                    $primarySnapshot = $calc['rate_snapshot'];
                } catch (ValidationException $e) {
                    if ($passedModelPrice === null) {
                        throw $e;
                    }
                    $primaryPrice = (float) $passedModelPrice;
                    $primarySnapshot = [
                        'tier_id' => null,
                        'duration_unit' => $durationUnit,
                        'duration_value' => $durationValue,
                        'duration_hours' => $durationHours,
                        'price' => $primaryPrice,
                        'extension_hourly_rate' => 15000.00,
                        'model_id' => $model->id,
                        'model_name' => $model->name,
                        'brand' => $model->brand,
                        'category_name' => $model->category?->name,
                        'calculated_at' => Carbon::now()->toIso8601String(),
                    ];
                }
                $primarySubtotal = $primaryPrice;
            } else {
                $primaryAssets = Asset::withoutGlobalScopes()
                    ->with(['model.category'])
                    ->whereIn('id', $rawPrimaryIds)
                    ->where('affiliate_id', $affiliateId)
                    ->lockForUpdate()
                    ->get();

                if ($primaryAssets->count() !== count($rawPrimaryIds)) {
                    throw ValidationException::withMessages([
                        'asset_ids' => ['Sebagian perangkat yang dipilih tidak ditemukan di cabang ini.'],
                    ]);
                }

                foreach ($primaryAssets as $asset) {
                    if (!in_array($asset->status, BookingAvailabilityService::RENTABLE_ASSET_STATUSES, true)) {
                        throw ValidationException::withMessages([
                            'asset_ids' => ["Perangkat {$asset->asset_code} tidak dapat dibooking (status: {$asset->status})."],
                        ]);
                    }
                    if (!$this->pricingService->isAssetAvailable($asset, $startAt, $endAt)) {
                        throw ValidationException::withMessages([
                            'asset_ids' => ["Perangkat {$asset->asset_code} sudah memiliki jadwal sewa lain pada waktu tersebut."],
                        ]);
                    }
                    $cap = $this->availabilityService->getBranchModelCapacity(
                        $affiliateId,
                        (int) $asset->model_id,
                        $startAt,
                        $endAt
                    );
                    if ($cap['available_slots'] < 1) {
                        throw ValidationException::withMessages([
                            'asset_ids' => ["Kapasitas model {$asset->model?->name} pada jadwal tersebut sudah penuh."],
                        ]);
                    }
                }

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
                    ->whereIn('id', $rawAddonIds)
                    ->where('affiliate_id', $affiliateId)
                    ->lockForUpdate()
                    ->get();

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
                $passedPrice = $data['unit_prices'][$asset->id] ?? $data['unit_prices'][(string) $asset->id] ?? null;
                try {
                    $calc = $this->pricingService->calculate($asset->model, $durationUnit, $durationValue, $startAt);
                    $unitPrice = $passedPrice !== null ? (float) $passedPrice : (float) $calc['price'];
                    $snapshot = $calc['rate_snapshot'];
                } catch (ValidationException $e) {
                    if ($passedPrice === null) {
                        throw $e;
                    }
                    $unitPrice = (float) $passedPrice;
                    $snapshot = [
                        'tier_id' => null,
                        'duration_unit' => $durationUnit,
                        'duration_value' => $durationValue,
                        'duration_hours' => $durationHours,
                        'price' => $unitPrice,
                        'extension_hourly_rate' => 15000.00,
                        'model_id' => $asset->model?->id,
                        'model_name' => $asset->model?->name,
                        'brand' => $asset->model?->brand,
                        'category_name' => $asset->model?->category?->name,
                        'calculated_at' => Carbon::now()->toIso8601String(),
                    ];
                }
                $primarySubtotal += $unitPrice;
                $itemPrices[$asset->id] = $unitPrice;
                $itemSnapshots[$asset->id] = $snapshot;
            }

            $addonSubtotal = 0.0;
            foreach ($addonAssets as $addon) {
                $passedPrice = $data['unit_prices'][$addon->id] ?? $data['unit_prices'][(string) $addon->id] ?? null;
                try {
                    $calc = $this->pricingService->calculate($addon->model, $durationUnit, $durationValue, $startAt);
                    $unitPrice = $passedPrice !== null ? (float) $passedPrice : (float) $calc['price'];
                    $snapshot = $calc['rate_snapshot'];
                } catch (ValidationException) {
                    $firstTier = $addon->model?->pricingTiers()->where('is_active', true)->first();
                    $unitPrice = $passedPrice !== null ? (float) $passedPrice : ($firstTier ? (float) $firstTier->price : 20000.00);
                    $snapshot = [
                        'tier_id' => $firstTier?->id,
                        'duration_unit' => $durationUnit,
                        'duration_value' => $durationValue,
                        'duration_hours' => $durationHours,
                        'price' => $unitPrice,
                        'model_id' => $addon->model?->id,
                        'model_name' => $addon->model?->name,
                    ];
                }
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
            if (!empty($data['payment_method_id'])) {
                $amountPaidNow = $paidAmountRequested !== null
                    ? min($totalBill, max(0.0, $paidAmountRequested))
                    : $totalBill;
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

            $booking = Booking::withoutGlobalScopes()->create([
                'business_id' => $businessId,
                'booking_code' => $bookingCode,
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
                'collateral_type' => $data['collateral_type'] ?? 'KTP Asli',
                'collateral_secured' => false,
                'collateral_storage_slot' => $data['collateral_storage_slot'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($isFloating) {
                BookingItem::create([
                    'booking_id' => $booking->id,
                    'asset_id' => null,
                    'asset_model_id' => $primaryModelId,
                    'item_type' => 'primary',
                    'unit_price' => $primaryPrice,
                    'duration_unit' => $durationUnit,
                    'duration_value' => $durationValue,
                    'rate_snapshot' => $primarySnapshot,
                    'status' => 'active',
                ]);
            } else {
                foreach ($primaryAssets as $asset) {
                    BookingItem::create([
                        'booking_id' => $booking->id,
                        'asset_id' => $asset->id,
                        'asset_model_id' => $asset->model_id,
                        'item_type' => 'primary',
                        'unit_price' => $itemPrices[$asset->id],
                        'duration_unit' => $durationUnit,
                        'duration_value' => $durationValue,
                        'rate_snapshot' => $itemSnapshots[$asset->id],
                        'status' => 'active',
                    ]);
                }
            }

            foreach ($addonAssets as $addon) {
                BookingItem::create([
                    'booking_id' => $booking->id,
                    'asset_id' => $addon->id,
                    'asset_model_id' => $addon->model_id,
                    'item_type' => 'addon',
                    'unit_price' => $itemPrices[$addon->id],
                    'duration_unit' => $durationUnit,
                    'duration_value' => $durationValue,
                    'rate_snapshot' => $itemSnapshots[$addon->id],
                    'status' => 'active',
                ]);
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
                    $txType === 'dp' ? 'Pembayaran DP booking terjadwal' : 'Pembayaran lunas booking terjadwal'
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
     * Check-in / Handover a scheduled booking (`booked` -> `rented`) and assign a physical unit if floating.
     */
    public function checkInBooking(Booking $booking, array $data, User $staff): Booking
    {
        return DB::transaction(function () use ($booking, $data, $staff) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['items.asset.model', 'items.assetModel', 'assetModel', 'assetUnit', 'affiliate', 'payments'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover'], true)) {
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
                $lockedBooking->load(['items.asset.model', 'items.assetModel', 'assetModel', 'assetUnit', 'affiliate', 'payments']);
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

            $startAt = Carbon::parse($lockedBooking->start_at);
            $endAt = Carbon::parse($lockedBooking->end_at);
            if (!$this->pricingService->isAssetAvailable($targetAsset, $startAt, $endAt, (int) $lockedBooking->id)) {
                throw ValidationException::withMessages([
                    'asset_unit_id' => ["Unit {$targetAsset->asset_code} memiliki jadwal sewa lain yang bertabrakan."],
                ]);
            }

            $targetAsset->update(['status' => 'rented']);

            $primaryItem = $lockedBooking->items
                ->first(fn ($item) => $item->item_type === 'primary' || $item->item_type === null);

            if ($primaryItem) {
                $primaryItem->update([
                    'asset_id' => $targetAsset->id,
                    'asset_model_id' => $targetAsset->model_id,
                    'status' => 'active',
                ]);
            } else {
                BookingItem::create([
                    'booking_id' => $lockedBooking->id,
                    'asset_id' => $targetAsset->id,
                    'asset_model_id' => $targetAsset->model_id,
                    'item_type' => 'primary',
                    'unit_price' => (float) ($lockedBooking->primary_subtotal ?? $lockedBooking->subtotal_amount ?? 0),
                    'duration_unit' => $lockedBooking->duration_unit ?? 'hour',
                    'duration_value' => $lockedBooking->duration_value ?? $lockedBooking->duration_hours,
                    'status' => 'active',
                ]);
            }

            $lockedBooking->update([
                'status' => 'rented',
                'picked_up_at' => Carbon::now(),
                'asset_model_id' => $lockedBooking->asset_model_id ?? $targetAsset->model_id,
                'asset_unit_id' => $targetAsset->id,
                'is_overdue_pickup' => false,
                'collateral_type' => $data['collateral_type'] ?? $lockedBooking->collateral_type ?? 'KTP Asli',
                'collateral_storage_slot' => $data['collateral_storage_slot'] ?? $lockedBooking->collateral_storage_slot ?? 'Brankas A, Slot 1',
                'collateral_secured' => true,
            ]);

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
                ],
                null,
                request()?->ip(),
                $lockedBooking->affiliate_id
            );

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
     * Transition a booked reservation to `no_show`, stamp `no_show_at`, and forfeit paid DP.
     */
    public function markNoShow(
        Booking $booking,
        ?User $actor = null,
        ?string $reason = null,
        bool $isAutomated = false
    ): Booking {
        return DB::transaction(function () use ($booking, $actor, $reason, $isAutomated) {
            $lockedBooking = Booking::withoutGlobalScopes()
                ->with(['payments', 'assetUnit', 'customer', 'assetModel', 'affiliate'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$lockedBooking->booking_code} tidak dapat ditandai No-Show (status saat ini: {$lockedBooking->status})."],
                ]);
            }

            $forfeitedDp = $lockedBooking->paid_amount;

            if ($lockedBooking->asset_unit_id) {
                $pinnedAsset = Asset::withoutGlobalScopes()
                    ->where('id', $lockedBooking->asset_unit_id)
                    ->lockForUpdate()
                    ->first();
                if ($pinnedAsset && $pinnedAsset->status === 'reserved') {
                    $pinnedAsset->update(['status' => 'ready']);
                }
            }

            $lockedBooking->update([
                'status' => 'no_show',
                'no_show_at' => Carbon::now(),
                'forfeited_dp_amount' => $forfeitedDp,
                'is_overdue_pickup' => false,
                'notes' => $reason
                    ? trim(($lockedBooking->notes ? $lockedBooking->notes . "\n" : '') . "[No-Show] {$reason}")
                    : $lockedBooking->notes,
            ]);

            if ($actor) {
                AuditLogService::log(
                    $actor,
                    $isAutomated ? 'booking.auto_noshow' : 'booking.marked_no_show',
                    'Booking',
                    $lockedBooking->booking_code,
                    [
                        'booking_code' => $lockedBooking->booking_code,
                        'forfeited_dp_amount' => $forfeitedDp,
                        'reason' => $reason,
                        'automated' => $isAutomated,
                    ],
                    null,
                    request()?->ip(),
                    $lockedBooking->affiliate_id
                );
            }

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

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Batas ambil hanya dapat diperpanjang untuk booking yang belum check-in."],
                ]);
            }

            $now = Carbon::now();
            $currentGrace = $lockedBooking->grace_expires_at ? Carbon::parse($lockedBooking->grace_expires_at) : null;
            $base = ($currentGrace && $currentGrace->gt($now)) ? $currentGrace->copy() : $now->copy();
            $targetGrace = $newGraceExpiresAt ?? $base->addMinutes(max(5, $additionalMinutes ?? 30));

            $lockedBooking->update([
                'grace_expires_at' => $targetGrace,
                'is_overdue_pickup' => false,
            ]);

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

            if (!in_array($lockedBooking->status, ['booked', 'reserved', 'confirmed', 'waiting_handover'], true)) {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$lockedBooking->booking_code} tidak dapat dijadwalkan ulang (status saat ini: {$lockedBooking->status})."],
                ]);
            }

            $shiftMeta = $this->shiftBookingSchedule($lockedBooking, $newStartAt, $keepOriginalEndAt, true);

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
                    ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange'])
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

        $affiliateId = !empty($data['affiliate_id']) ? (int) $data['affiliate_id'] : null;
        if (empty($affiliateId)) {
            $affiliateId = $tenantContext->getBranchId();
        }
        if (empty($affiliateId) && !empty($cashier->affiliate_id)) {
            $affiliateId = $cashier->affiliate_id;
        }
        if (empty($affiliateId) && $businessId) {
            $defaultBranch = Affiliate::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('is_central', 'desc')
                ->first();
            $affiliateId = $defaultBranch?->id;
        }

        if (empty($affiliateId)) {
            throw ValidationException::withMessages([
                'affiliate_id' => ['Cabang/outlet tidak dapat ditentukan. Harap tentukan cabang aktif.'],
            ]);
        }

        $affiliate = Affiliate::find($affiliateId);
        if (!$affiliate) {
            throw ValidationException::withMessages([
                'affiliate_id' => ['Cabang yang dipilih tidak ditemukan.'],
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
                if (empty($data['manager_pin'])) {
                    throw ValidationException::withMessages([
                        'manager_pin' => ['Diskon manual melebihi batas wewenang kasir. Diperlukan PIN Manager.'],
                    ]);
                }
                $managers = User::where(function ($q) use ($affiliateId, $businessId) {
                    $q->where('affiliate_id', $affiliateId);
                    if ($businessId) {
                        $q->orWhereHas('businessMembers', function ($bm) use ($businessId) {
                            $bm->where('business_id', $businessId)->whereIn('role', ['owner', 'admin']);
                        });
                    }
                })->whereNotNull('manager_override_pin')->get();

                $matchingManager = $managers->first(fn ($mgr) => password_verify($data['manager_pin'], $mgr->manager_override_pin));
                if (!$matchingManager) {
                    throw ValidationException::withMessages([
                        'manager_pin' => ['PIN Manager tidak valid.'],
                    ]);
                }
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
        string $notes
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
        $datePrefix = Carbon::now()->format('ymd');
        $txEntropy = strtoupper(Str::random(4));
        $txCount = (int) PaymentTransaction::where('business_id', $businessId)->count() + 1;
        $txCode = sprintf('TX-%s-%04d-%s', $datePrefix, $txCount, $txEntropy);
        $receiptNumber = sprintf('REC-%s-%s-%s', $affiliate->code ?? 'CTR', $datePrefix, strtoupper(Str::random(6)));

        return PaymentTransaction::create([
            'business_id' => $businessId,
            'branch_id' => $affiliate->id,
            'shift_id' => $activeShift?->id,
            'transaction_code' => $txCode,
            'receipt_number' => $receiptNumber,
            'booking_id' => $booking->id,
            'payment_method_id' => $paymentMethod->id,
            'processed_by' => $cashier->id,
            'type' => $txType,
            'status' => 'completed',
            'direction' => 'inflow',
            'amount' => $amount,
            'cash_received' => $cashReceived,
            'cash_change' => $cashChange,
            'notes' => $notes,
            'transacted_at' => Carbon::now(),
        ]);
    }
}
