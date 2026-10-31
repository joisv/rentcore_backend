<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Asset;
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
        protected PricingService $pricingService
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

            if (!empty($data['customer_address']) && $customer->address !== $data['customer_address']) {
                $customer->update(['address' => $data['customer_address']]);
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
            $startAt = !empty($data['start_at']) ? Carbon::parse($data['start_at']) : Carbon::now();
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

            // 7. Create Booking (Direct activation: status = 'rented')
            $booking = Booking::withoutGlobalScopes()->create([
                'business_id' => $businessId,
                'booking_code' => $bookingCode,
                'affiliate_id' => $affiliateId,
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

            return $booking->load(['items.asset.model', 'customer', 'payments.paymentMethod', 'voucher']);
        });
    }
}
