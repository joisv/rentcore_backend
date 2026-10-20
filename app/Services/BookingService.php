<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BusinessMember;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
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

            $assetIds = (array)($data['asset_ids'] ?? []);

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
                    Storage::disk('public')->put($identityFilePath, $decoded);
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
            $subtotal = 0.00;
            $itemPrices = [];
            $itemSnapshots = [];
            foreach ($assets as $asset) {
                try {
                    $calc = $this->pricingService->calculate($asset->model, $durationUnit, $durationValue, $startAt);
                    $unitPrice = isset($data['unit_prices'][$asset->id]) ? (float)$data['unit_prices'][$asset->id] : $calc['price'];
                    $snapshot = $calc['rate_snapshot'];
                } catch (ValidationException $e) {
                    if (isset($data['unit_prices'][$asset->id])) {
                        $unitPrice = (float)$data['unit_prices'][$asset->id];
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
                    } else {
                        throw $e;
                    }
                }

                $subtotal += $unitPrice;
                $itemPrices[$asset->id] = $unitPrice;
                $itemSnapshots[$asset->id] = $snapshot;
            }

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
                    $manager = User::where(function ($q) use ($affiliateId, $businessId) {
                            $q->where('affiliate_id', $affiliateId);
                            if ($businessId) {
                                $q->orWhereHas('businessMembers', function ($bm) use ($businessId) {
                                    $bm->where('business_id', $businessId)->whereIn('role', ['owner', 'admin']);
                                });
                            }
                        })
                        ->whereNotNull('manager_override_pin')
                        ->first();

                    if (!$manager || !password_verify($data['manager_pin'], $manager->manager_override_pin)) {
                        throw ValidationException::withMessages([
                            'manager_pin' => ['PIN Manager tidak valid.'],
                        ]);
                    }
                    $approvedBy = $manager->id;
                } else {
                    $approvedBy = $cashier->id;
                }
            }

            $depositFee = (float)($data['deposit_fee'] ?? 0.00);
            $totalBill = max(0, $subtotal + $depositFee - $discountAmount);

            // 6. Generate Booking Code: RC-YYMMDD-XXXX
            $datePrefix = Carbon::now()->format('ymd');
            $countToday = Booking::withoutGlobalScopes()
                ->whereDate('created_at', Carbon::today())
                ->count() + 1;
            $bookingCode = sprintf('RC-%s-%04d', $datePrefix, $countToday);

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
                BookingItem::create([
                    'booking_id' => $booking->id,
                    'asset_id' => $asset->id,
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
            $isCash = strtoupper($paymentMethod->code) === 'CASH';

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

            $txCode = sprintf('TX-%s-%04d', $datePrefix, PaymentTransaction::count() + 1);
            $receiptNumber = sprintf('REC-%s-%s-%04d', $affiliate->code ?? 'CTR', $datePrefix, $booking->id);

            PaymentTransaction::create([
                'business_id' => $businessId,
                'branch_id' => $affiliateId,
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
