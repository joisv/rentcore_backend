<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\RentalUnitExchange;
use App\Models\User;
use App\Services\ShiftEnforcementService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExchangeService
{
    public function __construct(
        protected PricingService $pricingService
    ) {}

    /**
     * Get candidate replacement units in the same branch that are in 'ready' status,
     * along with calculated pricing tier comparisons relative to the current unit.
     */
    public function getAvailableReplacements(Booking $booking, ?int $currentAssetId = null): array
    {
        $branchId = $booking->affiliate_id;

        // Determine current unit rate and model
        $currentItem = null;
        if ($currentAssetId) {
            $currentItem = $booking->items()->where('asset_id', $currentAssetId)->first();
        }
        if (!$currentItem) {
            $currentItem = $booking->activeItems()->with('asset.model')->first()
                ?? $booking->items()->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'active');
                })->with('asset.model')->latest('id')->first()
                ?? $booking->items()->first();
        }

        $currentRate = $currentItem ? (float)$currentItem->unit_price : 0.0;
        $currentModelId = $currentItem?->asset?->model_id;

        // Duration parameters from booking
        $durationUnit = $booking->duration_unit ?? 'hour';
        $durationValue = $booking->duration_value ?? ($booking->duration_hours ?? 1);

        // Exclude all assets currently assigned or active on this booking
        $excludedAssetIds = $booking->items()
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->pluck('asset_id')
            ->toArray();

        if ($currentAssetId && !in_array($currentAssetId, $excludedAssetIds)) {
            $excludedAssetIds[] = $currentAssetId;
        }
        if ($currentItem?->asset_id && !in_array($currentItem->asset_id, $excludedAssetIds)) {
            $excludedAssetIds[] = $currentItem->asset_id;
        }

        // Fetch candidate assets in the same branch that are 'ready'
        $assets = Asset::withoutGlobalScopes()
            ->where('affiliate_id', $branchId)
            ->where('status', 'ready')
            ->whereNotIn('id', $excludedAssetIds)
            ->with(['model.category'])
            ->get();

        $candidates = [];

        foreach ($assets as $asset) {
            $model = $asset->model;
            if (!$model) {
                continue;
            }

            // Calculate rate for replacement unit
            $replacementRate = $currentRate;
            $rateCalculationSuccess = false;
            try {
                $pricingResult = $this->pricingService->calculate(
                    $model,
                    $durationUnit,
                    $durationValue,
                    Carbon::parse($booking->start_at)
                );
                $replacementRate = (float)$pricingResult['price'];
                $rateCalculationSuccess = true;
            } catch (Exception $e) {
                // If model has a fallback tier or hourly rate
                $tier = $model->pricingTiers()->where('is_active', true)->first();
                if ($tier) {
                    $replacementRate = (float)$tier->price;
                    $rateCalculationSuccess = true;
                }
            }

            $differential = $replacementRate - $currentRate;

            $tierCategory = 'same_tier';
            if ($model->id === $currentModelId || abs($differential) < 0.01) {
                $tierCategory = 'same_tier';
                $differential = 0.0;
            } elseif ($differential > 0) {
                $tierCategory = 'upgrade';
            } else {
                $tierCategory = 'downgrade';
            }

            $candidates[] = [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'serial_number' => $asset->serial_number,
                'condition_grade' => $asset->condition_grade ?? 'A',
                'battery_health' => $asset->battery_health,
                'model' => [
                    'id' => $model->id,
                    'name' => $model->name,
                    'brand' => $model->brand,
                    'storage_capacity' => $model->storage_capacity,
                    'color' => $model->color,
                    'category' => $model->category?->name,
                    'requires_frp_check' => $model->requires_frp_check,
                ],
                'calculated_rate' => $replacementRate,
                'price_differential' => $differential,
                'tier_category' => $tierCategory,
                'rate_calculation_success' => $rateCalculationSuccess,
            ];
        }

        return [
            'booking_id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'current_asset' => $currentItem ? [
                'asset_id' => $currentItem->asset_id,
                'asset_code' => $currentItem->asset?->asset_code,
                'model_name' => $currentItem->asset?->model?->name,
                'unit_price' => $currentRate,
                'duration_unit' => $durationUnit,
                'duration_value' => $durationValue,
            ] : null,
            'candidates' => $candidates,
        ];
    }

    /**
     * Atomically execute a rental unit exchange with inventory locking,
     * financial differential collection/waiver, audit logging, and receipt generation.
     */
    public function executeExchange(Booking $booking, array $data, User $cashier): array
    {
        return DB::transaction(function () use ($booking, $data, $cashier) {
            // 1. Validate Booking is currently active
            if ($booking->status !== 'rented') {
                throw ValidationException::withMessages([
                    'booking' => ["Sewa dengan kode {$booking->booking_code} tidak berstatus aktif ('rented'). Unit tidak dapat ditukar."],
                ]);
            }

            // 2. Lock Booking row
            $booking = Booking::with(['items.asset.model', 'affiliate'])
                ->lockForUpdate()
                ->findOrFail($booking->id);

            $oldAssetId = !empty($data['old_asset_id']) ? (int)$data['old_asset_id'] : null;
            $newAssetId = (int)$data['new_asset_id'];

            // 3. Locate active BookingItem for old asset
            if ($oldAssetId) {
                $oldBookingItem = $booking->items()
                    ->where('asset_id', $oldAssetId)
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', 'active');
                    })
                    ->first();
            } else {
                $oldBookingItem = $booking->activeItems()->first()
                    ?? $booking->items()->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', 'active');
                    })->latest('id')->first();
                $oldAssetId = $oldBookingItem?->asset_id;
            }

            if (!$oldBookingItem || !$oldAssetId) {
                throw ValidationException::withMessages([
                    'old_asset_id' => ['Unit yang dilaporkan bermasalah tidak ditemukan dalam daftar unit aktif sewa ini.'],
                ]);
            }

            if ($oldAssetId === $newAssetId) {
                throw ValidationException::withMessages([
                    'new_asset_id' => ['Unit pengganti tidak boleh sama dengan unit yang ditukar.'],
                ]);
            }

            // Guard: ensure new asset is not already an active item in this booking
            $currentlyActiveIds = $booking->items()
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'active');
                })
                ->pluck('asset_id')
                ->toArray();

            if (in_array($newAssetId, $currentlyActiveIds)) {
                throw ValidationException::withMessages([
                    'new_asset_id' => ['Unit pengganti saat ini telah aktif ditugaskan pada sewa ini.'],
                ]);
            }

            // 4. Lock and validate Old Asset
            $oldAsset = Asset::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($oldAssetId);

            // 5. Lock and validate New Asset
            $newAsset = Asset::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($newAssetId);

            if ((int)$newAsset->affiliate_id !== (int)$booking->affiliate_id) {
                throw ValidationException::withMessages([
                    'new_asset_id' => ['Unit pengganti harus berasal dari cabang sewa aktif yang sama.'],
                ]);
            }

            if ($newAsset->status !== 'ready') {
                throw ValidationException::withMessages([
                    'new_asset_id' => ["Unit pengganti ({$newAsset->asset_code}) saat ini tidak dalam status siap sewa ('ready'). Status: {$newAsset->status}."],
                ]);
            }

            // 6. Security Guard: iCloud / FRP Sign-Out Verification for Apple/Samsung hardware
            $oldModel = $oldAsset->model;
            $requiresFrp = false;
            if ($oldModel) {
                $isFrpBrand = in_array(strtolower($oldModel->brand ?? ''), ['apple', 'samsung', 'google', 'xiaomi', 'oppo', 'vivo']);
                $isFrpName = (bool)preg_match('/(iphone|ipad|galaxy|pixel)/i', $oldModel->name ?? '');
                $requiresFrp = $oldModel->requires_frp_check || $isFrpBrand || $isFrpName;
            }

            $icloudVerified = !empty($data['icloud_signout_verified']);
            if ($requiresFrp && !$icloudVerified) {
                throw ValidationException::withMessages([
                    'icloud_signout_verified' => ['Akun iCloud / Apple ID / Google FRP wajib dipastikan telah dikeluarkan (Sign Out) dari unit sebelum penggantian dapat diproses.'],
                ]);
            }

            // 7. Calculate Pricing & Rate Differential
            $durationUnit = $booking->duration_unit ?? 'hour';
            $durationValue = $booking->duration_value ?? ($booking->duration_hours ?? 1);
            $newModel = $newAsset->model;

            $oldUnitRate = (float)$oldBookingItem->unit_price;
            $newUnitRate = $oldUnitRate;
            $newSnapshot = null;

            try {
                $calc = $this->pricingService->calculate(
                    $newModel,
                    $durationUnit,
                    $durationValue,
                    Carbon::parse($booking->start_at)
                );
                $newUnitRate = (float)$calc['price'];
                $newSnapshot = $calc['rate_snapshot'];
            } catch (Exception $e) {
                // If same model, inherit old unit rate
                if ($newModel->id === $oldModel?->id) {
                    $newUnitRate = $oldUnitRate;
                    $newSnapshot = $oldBookingItem->rate_snapshot;
                } else {
                    $tier = $newModel->pricingTiers()->where('is_active', true)->first();
                    if ($tier) {
                        $newUnitRate = (float)$tier->price;
                    }
                }
            }

            $rawDifferential = $newUnitRate - $oldUnitRate;
            $priceDifferential = max(0, $rawDifferential); // positive upgrade delta
            $isFeeWaived = !empty($data['fee_waived']);
            $managerApprovedBy = null;

            // 8. Authorize Fee Waiver or Manager Override if requested
            if ($isFeeWaived || !empty($data['manager_pin'])) {
                if (empty($data['manager_pin'])) {
                    throw ValidationException::withMessages([
                        'manager_pin' => ['Penghapusan / pembebasan biaya selisih tukar unit memerlukan otorisasi PIN Manager.'],
                    ]);
                }

                $businessId = $booking->business_id;
                $affiliateId = $booking->affiliate_id;

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

                $managerApprovedBy = $matchingManager->id;
            }

            // If fee is waived by manager, differential payable is 0
            $payableDifferential = $isFeeWaived ? 0.00 : $priceDifferential;

            // 9. Process Payment Transaction if differential > 0
            $paymentTx = null;
            if ($payableDifferential > 0) {
                if (empty($data['payment_method_id'])) {
                    throw ValidationException::withMessages([
                        'payment_method_id' => ['Metode pembayaran diperlukan untuk melunasi selisih tukar unit (Rp ' . number_format($payableDifferential, 0, ',', '.') . ').'],
                    ]);
                }

                $paymentMethod = PaymentMethod::findOrFail($data['payment_method_id']);
                $isCash = strtoupper($paymentMethod->code) === 'CASH' || stripos($paymentMethod->name, 'tunai') !== false;

                $branchId = (int)($booking->affiliate_id ?? $cashier->affiliate_id);

                // Enforce business & branch payment method availability
                $business = \App\Models\Business::find($booking->business_id);
                if ($business && !$business->isPaymentMethodEnabled($paymentMethod, $branchId)) {
                    throw ValidationException::withMessages([
                        'payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
                    ]);
                }

                $activeShift = ShiftEnforcementService::requireActiveShift(
                    $branchId,
                    $cashier,
                    $isCash,
                    'memproses pembayaran selisih tukar unit di meja kasir'
                );

                if ($isCash && isset($data['cash_received']) && (float)$data['cash_received'] < $payableDifferential) {
                    throw ValidationException::withMessages([
                        'cash_received' => [
                            'Jumlah uang tunai yang diterima (Rp ' . number_format((float)$data['cash_received'], 0, ',', '.') . 
                            ') kurang dari total selisih biaya (Rp ' . number_format($payableDifferential, 0, ',', '.') . ').'
                        ],
                    ]);
                }

                $cashReceived = $isCash ? (float)($data['cash_received'] ?? $payableDifferential) : $payableDifferential;
                $cashChange = $isCash ? max(0, $cashReceived - $payableDifferential) : 0.00;

                $datePrefix = Carbon::now()->format('ymd');
                $countToday = PaymentTransaction::withoutGlobalScopes()
                    ->whereDate('created_at', Carbon::today())
                    ->count() + 1;
                $txCode = sprintf('TX-EXC-%s-%04d', $datePrefix, $countToday);
                $receiptNumber = sprintf('REC-EXC-%s-%s-%04d', $booking->affiliate->code ?? 'CTR', $datePrefix, $booking->id);

                $paymentTx = PaymentTransaction::create([
                    'business_id' => $booking->business_id,
                    'branch_id' => $booking->affiliate_id,
                    'shift_id' => $activeShift->id,
                    'transaction_code' => $txCode,
                    'receipt_number' => $receiptNumber,
                    'booking_id' => $booking->id,
                    'payment_method_id' => $paymentMethod->id,
                    'processed_by' => $cashier->id,
                    'type' => 'exchange',
                    'status' => 'completed',
                    'direction' => 'inflow',
                    'amount' => $payableDifferential,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'idempotency_key' => $data['idempotency_key'] ?? (string)Str::uuid(),
                    'notes' => 'Pelunasan selisih upgrade tukar unit dari ' . ($oldModel->name ?? 'Unit Lama') . ' ke ' . ($newModel->name ?? 'Unit Baru'),
                    'transacted_at' => Carbon::now(),
                ]);

                // Update booking total bill
                $booking->total_bill = (float)$booking->total_bill + $payableDifferential;
                $booking->save();
            }

            // 10. Update Old Asset -> 'maintenance' (Quarantine Defect)
            $oldDisposition = $data['old_asset_disposition'] ?? 'maintenance';
            $oldGrade = $data['old_unit_condition_grade'] ?? 'B';
            $oldAsset->update([
                'status' => $oldDisposition,
                'condition_grade' => $oldGrade,
                'notes' => 'Karantina kendala tukar unit [' . Carbon::now()->format('d/m/Y H:i') . ']: ' . ($data['reported_issue_category'] ?? 'hardware') . ' - ' . ($data['customer_complaint'] ?? ''),
            ]);

            // 11. Update New Asset -> 'rented'
            $newAsset->update([
                'status' => 'rented',
            ]);

            // 12. Deactivate Old BookingItem
            $oldBookingItem->update([
                'status' => 'exchanged',
                'exchanged_at' => Carbon::now(),
            ]);

            // 13. Create New BookingItem for Replacement Asset
            $newBookingItem = BookingItem::create([
                'booking_id' => $booking->id,
                'asset_id' => $newAsset->id,
                'unit_price' => $newUnitRate,
                'duration_unit' => $durationUnit,
                'duration_value' => $durationValue,
                'rate_snapshot' => $newSnapshot,
                'status' => 'active',
            ]);

            // 14. Create RentalUnitExchange Record
            $exchange = RentalUnitExchange::create([
                'business_id' => $booking->business_id,
                'branch_id' => $booking->affiliate_id,
                'booking_id' => $booking->id,
                'old_asset_id' => $oldAsset->id,
                'new_asset_id' => $newAsset->id,
                'reported_issue_category' => $data['reported_issue_category'],
                'customer_complaint' => $data['customer_complaint'],
                'staff_diagnosis' => $data['staff_diagnosis'] ?? null,
                'old_unit_condition_grade' => $oldGrade,
                'icloud_signout_verified' => $icloudVerified,
                'old_asset_disposition' => $oldDisposition,
                'old_unit_rate' => $oldUnitRate,
                'new_unit_rate' => $newUnitRate,
                'price_differential' => $payableDifferential,
                'fee_waived' => $isFeeWaived,
                'waiver_reason' => $data['waiver_reason'] ?? null,
                'payment_transaction_id' => $paymentTx?->id,
                'handled_by' => $cashier->id,
                'manager_approved_by' => $managerApprovedBy,
                'exchanged_at' => Carbon::now(),
            ]);

            // 15. Immutable Security Audit Log
            AuditLogService::log(
                $cashier,
                'booking.unit_exchanged',
                'RentalUnitExchange',
                $exchange->id,
                [
                    'booking_code' => $booking->booking_code,
                    'old_asset_code' => $oldAsset->asset_code,
                    'old_model' => $oldModel?->name,
                    'new_asset_code' => $newAsset->asset_code,
                    'new_model' => $newModel?->name,
                    'issue_category' => $data['reported_issue_category'],
                    'complaint' => $data['customer_complaint'],
                    'price_differential' => $payableDifferential,
                    'fee_waived' => $isFeeWaived,
                    'payment_transaction_code' => $paymentTx?->transaction_code,
                    'handled_by' => $cashier->name ?? $cashier->id,
                ],
                [
                    'previous_asset_id' => $oldAsset->id,
                ],
                request()?->ip(),
                $booking->affiliate_id
            );

            return [
                'success' => true,
                'message' => 'Unit sewa berhasil ditukar. Unit lama dimasukkan ke karantina dan unit baru aktif disewa.',
                'exchange' => $exchange->load([
                    'oldAsset.model',
                    'newAsset.model',
                    'payment.paymentMethod',
                    'handler',
                    'manager',
                ]),
                'booking' => $booking->fresh([
                    'items.asset.model',
                    'exchanges.oldAsset.model',
                    'exchanges.newAsset.model',
                    'payments.paymentMethod',
                    'customer',
                ]),
                'payment' => $paymentTx,
                'receipt_data' => [
                    'receipt_number' => $paymentTx?->receipt_number ?? sprintf('REC-EXC-%s-%s-%04d', $booking->affiliate->code ?? 'CTR', Carbon::now()->format('ymd'), $booking->id),
                    'booking_code' => $booking->booking_code,
                    'customer_name' => $booking->customer?->name,
                    'customer_phone' => $booking->customer?->phone,
                    'branch_name' => $booking->affiliate?->name,
                    'cashier_name' => $cashier->name,
                    'old_asset_name' => $oldModel?->name,
                    'old_asset_code' => $oldAsset->asset_code,
                    'old_asset_serial' => $oldAsset->serial_number,
                    'reported_issue' => $data['customer_complaint'],
                    'new_asset_name' => $newModel?->name,
                    'new_asset_code' => $newAsset->asset_code,
                    'new_asset_serial' => $newAsset->serial_number,
                    'new_asset_battery' => $newAsset->battery_health,
                    'old_unit_rate' => $oldUnitRate,
                    'new_unit_rate' => $newUnitRate,
                    'price_differential' => $payableDifferential,
                    'payment_method' => $paymentTx?->paymentMethod?->name ?? 'Tanpa Biaya (Setara)',
                    'cash_received' => $paymentTx?->cash_received ?? 0.0,
                    'cash_change' => $paymentTx?->cash_change ?? 0.0,
                    'exchanged_at' => Carbon::now()->format('d M Y, H:i'),
                    'end_at' => Carbon::parse($booking->end_at)->format('d M Y, H:i'),
                ],
            ];
        });
    }
}
