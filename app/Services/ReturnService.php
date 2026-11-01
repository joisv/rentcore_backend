<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\CashDrawerShift;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\ReturnInspection;
use App\Models\User;
use App\Services\ShiftEnforcementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    /**
     * Process return inspection, enforce iCloud guard, compute late penalties with 60-min grace period.
     */
    public function processReturn(Booking $booking, array $data, User $inspector): ReturnInspection
    {
        return DB::transaction(function () use ($booking, $data, $inspector) {
            // Idempotent retry handling: if booking was already marked returned and idempotency key supplied
            if ($booking->status === 'returned') {
                if (!empty($data['idempotency_key'])) {
                    $existingInspection = ReturnInspection::where('booking_id', $booking->id)->first();
                    if ($existingInspection) {
                        return $existingInspection->load(['inspector', 'booking']);
                    }
                }
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$booking->booking_code} tidak dalam status sewa aktif (status saat ini: {$booking->status})."],
                ]);
            }

            if ($booking->status !== 'rented') {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$booking->booking_code} tidak dalam status sewa aktif (status saat ini: {$booking->status})."],
                ]);
            }

            // 1. Mandatory Critical Security Guard: Category-Aware Device Validation
            // Strictly scope validation by device ecosystem:
            // - iPhone / Apple devices: ONLY check Apple ID / iCloud Activation Lock. Strictly bypass Google/FRP.
            // - Android devices: Check Google FRP only if device is explicitly Android (operating_system == 'android' or category == 'android').
            // - Non-phone units (PlayStation, drones, powerbanks, tents, cameras): Bypass both completely.
            $requiresICloud = false;
            $requiresAndroidFrp = false;

            $checklist = $data['checklist_results'] ?? [];
            $platformHint = strtolower((string)($checklist['device_platform'] ?? ''));

            if ($booking->items->isNotEmpty()) {
                foreach ($booking->items as $item) {
                    $model = $item->asset?->model;
                    if (!$model) {
                        continue;
                    }
                    if ($item->item_type === 'addon' || $model->isAddon()) {
                        continue;
                    }

                    $category = $model->category;
                    $catSlug = strtolower((string)($category?->slug ?? ''));
                    $catName = strtolower((string)($category?->name ?? ''));
                    $brand = strtolower((string)($model->brand ?? ''));
                    $modelName = strtolower((string)($model->name ?? ''));
                    $assetCode = strtolower((string)($item->asset?->asset_code ?? ''));
                    $os = strtolower((string)($model->specifications['operating_system'] ?? $model->specifications['os'] ?? ''));

                    // A. Non-phone explicit exclusions (PlayStation, drones, powerbanks, outdoor gear/tents, cameras, audio, projectors)
                    $isNonPhoneCategory = (
                        str_contains($catSlug, 'konsol') || str_contains($catName, 'konsol') ||
                        str_contains($catSlug, 'console') || str_contains($catName, 'console') ||
                        str_contains($catSlug, 'game') || str_contains($catName, 'game') ||
                        str_contains($catSlug, 'kamera') || str_contains($catName, 'kamera') ||
                        str_contains($catSlug, 'camera') || str_contains($catName, 'camera') ||
                        str_contains($catSlug, 'proyektor') || str_contains($catName, 'proyektor') ||
                        str_contains($catSlug, 'projector') || str_contains($catName, 'projector') ||
                        str_contains($catSlug, 'outdoor') || str_contains($catName, 'outdoor') ||
                        str_contains($catSlug, 'tenda') || str_contains($catName, 'tenda') ||
                        str_contains($catSlug, 'camp') || str_contains($catName, 'camp') ||
                        str_contains($catSlug, 'drone') || str_contains($catName, 'drone') ||
                        str_contains($catSlug, 'powerbank') || str_contains($catName, 'powerbank') ||
                        str_contains($catSlug, 'audio') || str_contains($catName, 'audio') ||
                        str_contains($catSlug, 'lensa') || str_contains($catName, 'lensa')
                    );

                    $isNonPhoneModel = (
                        str_contains($modelName, 'playstation') || str_contains($modelName, 'ps4') || str_contains($modelName, 'ps5') ||
                        str_contains($modelName, 'nintendo') || str_contains($modelName, 'xbox') ||
                        str_contains($modelName, 'alpha') || str_contains($modelName, 'dslr') || str_contains($modelName, 'mirrorless') ||
                        str_contains($modelName, 'lumens') || str_contains($modelName, 'projector') || str_contains($modelName, 'proyektor') ||
                        str_contains($modelName, 'tenda') || str_contains($modelName, 'tent') || str_contains($modelName, 'carrier') ||
                        str_contains($modelName, 'drone') || str_contains($modelName, 'mavic') ||
                        str_contains($modelName, 'powerbank')
                    );

                    if ($isNonPhoneCategory || $isNonPhoneModel) {
                        continue;
                    }

                    // B. Apple / iPhone device check
                    // If device is Apple / iPhone / iOS hardware:
                    $isAppleDevice = (
                        $platformHint === 'ios' ||
                        str_contains($brand, 'apple') ||
                        str_contains($catSlug, 'apple') || str_contains($catName, 'apple') ||
                        str_contains($catSlug, 'iphone') || str_contains($catName, 'iphone') ||
                        str_contains($catSlug, 'ipad') || str_contains($catName, 'ipad') ||
                        str_contains($catSlug, 'ios') || str_contains($catName, 'ios') ||
                        str_contains($modelName, 'iphone') || str_contains($modelName, 'ipad') ||
                        str_contains($modelName, 'macbook') ||
                        str_starts_with($assetCode, 'iph') || str_starts_with($assetCode, 'app') ||
                        $os === 'ios'
                    );

                    if ($isAppleDevice) {
                        $requiresICloud = true;
                        // An Apple device NEVER requires Android Google FRP!
                        $requiresAndroidFrp = false;
                        break;
                    }

                    // C. Android device check: Strictly triggers ONLY when operating_system == 'android' or category == 'android'
                    // or explicitly recognized as Android smartphone brand/hardware (and never for Apple hardware)
                    $isAndroidBrand = in_array($brand, ['samsung', 'google', 'xiaomi', 'oppo', 'vivo', 'realme', 'infinix', 'poco', 'asus']);
                    $isAndroidPhone = $isAndroidBrand || (bool)preg_match('/(galaxy|pixel|redmi|poco|realme|infinix)/i', $modelName);
                    $isAndroidCat = str_contains($catSlug, 'android') || str_contains($catName, 'android');
                    $isAndroidOs = $os === 'android' || $platformHint === 'android';

                    if (!$isAppleDevice && ($isAndroidCat || $isAndroidOs || ($isAndroidPhone && $model->requires_frp_check))) {
                        $requiresAndroidFrp = true;
                    }
                }
            }

            // CRITICAL INVARIANT: If this booking involves Apple/iPhone hardware, Google FRP is strictly bypassed!
            if ($requiresICloud || $platformHint === 'ios') {
                $requiresAndroidFrp = false;
            }

            $isQuarantined = !empty($checklist['account_quarantined']) || ($checklist['account_status'] ?? '') === 'quarantined';

            if ($requiresICloud) {
                // Strictly Apple ID / iCloud validation
                if (empty($checklist['icloud_removed']) && !$isQuarantined) {
                    throw ValidationException::withMessages([
                        'checklist' => ['Akun iCloud / Apple ID wajib dipastikan telah dikeluarkan (Sign Out) sebelum pengembalian dapat diproses, atau tandai sebagai Karantina Akun jika memerlukan tindak lanjut khusus.'],
                    ]);
                }

                if ($isQuarantined && empty($data['staff_notes']) && empty($checklist['quarantine_notes'])) {
                    throw ValidationException::withMessages([
                        'staff_notes' => ['Catatan staf wajib diisi untuk mendokumentasikan alasan karantina akun dan rencana tindak lanjut.'],
                    ]);
                }
            } elseif ($requiresAndroidFrp) {
                // Strictly Android Google FRP validation
                $isFrpRemoved = !empty($checklist['frp_removed']) || !empty($checklist['google_account_removed']);
                if (!$isFrpRemoved && !$isQuarantined) {
                    throw ValidationException::withMessages([
                        'checklist' => ['Akun Google / FRP wajib dipastikan telah dikeluarkan (Sign Out) sebelum pengembalian dapat diproses, atau tandai sebagai Karantina Akun jika memerlukan tindak lanjut khusus.'],
                    ]);
                }

                if ($isQuarantined && empty($data['staff_notes']) && empty($checklist['quarantine_notes'])) {
                    throw ValidationException::withMessages([
                        'staff_notes' => ['Catatan staf wajib diisi untuk mendokumentasikan alasan karantina akun dan rencana tindak lanjut.'],
                    ]);
                }
            }

            $business = \App\Models\Business::find($booking->business_id);
            $businessTz = $business?->timezone ?: 'Asia/Jakarta';

            if (!empty($data['returned_at'])) {
                $rawReturnedAt = (string)$data['returned_at'];
                if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $rawReturnedAt)) {
                    $returnedAt = Carbon::parse($rawReturnedAt)->setTimezone('UTC');
                } else {
                    $returnedAt = Carbon::parse($rawReturnedAt, $businessTz)->setTimezone('UTC');
                }
            } else {
                $returnedAt = Carbon::now('UTC');
            }

            $expectedEndAt = Carbon::parse($booking->end_at)->setTimezone('UTC');

            // 2. Late Minutes & Grace Period Penalty Formula
            // Formula: max(0, ceil((late_minutes - 60) / 60)) * hourly_rate
            $lateMinutes = 0;
            $lateFee = 0.00;
            $hourlyRate = 15000.00;

            // Fetch hourly rate from primary item rate snapshot first, then model pricing tier, or default to 15,000 IDR/hr
            $firstItem = $booking->items->first();
            if ($firstItem) {
                $rateSnapshot = is_array($firstItem->rate_snapshot)
                    ? $firstItem->rate_snapshot
                    : json_decode($firstItem->rate_snapshot ?? '[]', true);

                if (!empty($rateSnapshot['extension_hourly_rate']) && (float)$rateSnapshot['extension_hourly_rate'] > 0) {
                    $hourlyRate = (float)$rateSnapshot['extension_hourly_rate'];
                } elseif ($firstItem->asset) {
                    $tier = PricingTier::where('model_id', $firstItem->asset->model_id)
                        ->where('is_active', true)
                        ->first();
                    if ($tier && (float)$tier->extension_hourly_rate > 0) {
                        $hourlyRate = (float)$tier->extension_hourly_rate;
                    }
                }
            }

            if ($returnedAt->gt($expectedEndAt)) {
                $lateMinutes = (int)floor($expectedEndAt->diffInMinutes($returnedAt));

                if ($lateMinutes > 60) {
                    $billableHours = (int)ceil(($lateMinutes - 60) / 60);
                    $lateFee = $billableHours * $hourlyRate;
                }
            }

            // Client-inspected penalty sync & tender tolerance guard:
            // If client submitted an inspected late_fee, sync if within 1 hour boundary tolerance
            // (prevents race-condition shortfall errors when cashier tender takes seconds to complete)
            if (isset($data['late_fee']) && is_numeric($data['late_fee'])) {
                $clientLateFee = (float)$data['late_fee'];
                if (abs($clientLateFee - $lateFee) <= $hourlyRate) {
                    $lateFee = $clientLateFee;
                }
            }

            $damageFee = (float)($data['damage_fee'] ?? 0.00);
            $totalPenalty = $lateFee + $damageFee;
            $depositRefunded = max(0, (float)$booking->deposit_fee - $totalPenalty);

            // Pre-validate Cash Shift & Cash Tender if penalty is being paid immediately or refund is owed
            $paymentMethod = null;
            $isCash = false;
            $activeShift = null;
            $branchId = (int)($inspector->affiliate_id ?? $booking->affiliate_id ?? $booking->branch_id);

            if ($depositRefunded > 0) {
                $activeShift = ShiftEnforcementService::requireActiveShift(
                    $branchId,
                    $inspector,
                    true,
                    'mengeluarkan uang titipan jaminan sewa (deposit refund) dari laci kasir'
                );
            }

            if ($totalPenalty > 0 && !empty($data['penalty_payment_method_id'])) {
                $paymentMethod = PaymentMethod::findOrFail($data['penalty_payment_method_id']);
                $isCash = strtoupper($paymentMethod->code) === 'CASH' || stripos($paymentMethod->name, 'tunai') !== false;

                // Enforce business & branch payment method availability
                $business = \App\Models\Business::find($booking->business_id);
                if ($business && !$business->isPaymentMethodEnabled($paymentMethod, $branchId)) {
                    throw ValidationException::withMessages([
                        'penalty_payment_method_id' => ["Metode pembayaran {$paymentMethod->name} sedang dinonaktifkan oleh pemilik usaha."],
                    ]);
                }

                // Cash Shift Guard: Enforce active shift before accepting penalty payment
                $activeShift = ShiftEnforcementService::requireActiveShift(
                    $branchId,
                    $inspector,
                    $isCash,
                    'menerima pembayaran denda pengembalian unit di kasir'
                );

                if ($isCash && isset($data['cash_received']) && (float)$data['cash_received'] < $totalPenalty) {
                    throw ValidationException::withMessages([
                        'cash_received' => [
                            'Jumlah uang tunai yang diterima (Rp ' . number_format((float)$data['cash_received'], 0, ',', '.') . 
                            ') kurang dari total tagihan denda (Rp ' . number_format($totalPenalty, 0, ',', '.') . ').'
                        ],
                    ]);
                }
            }

            // 3. Create ReturnInspection Record
            $conditionGrade = $data['condition_grade'] ?? 'A';
            $returnInspection = ReturnInspection::create([
                'booking_id' => $booking->id,
                'inspected_by' => $inspector->id,
                'returned_at' => $returnedAt,
                'condition_grade' => $conditionGrade,
                'battery_health_final' => $data['battery_health_final'] ?? null,
                'checklist_results' => $checklist,
                'accessories_returned' => $data['accessories_returned'] ?? ['cable' => true, 'adapter' => true],
                'late_minutes' => $lateMinutes,
                'late_fee' => $lateFee,
                'damage_fee' => $damageFee,
                'total_penalty' => $totalPenalty,
                'deposit_refunded' => $depositRefunded,
                'staff_notes' => $data['staff_notes'] ?? null,
            ]);

            // 4. Update Booking Status
            $newPaymentStatus = $booking->payment_status;
            if ($totalPenalty > 0 && empty($data['penalty_payment_method_id'])) {
                $newPaymentStatus = 'partial';
            }

            $booking->update([
                'status' => 'returned',
                'payment_status' => $newPaymentStatus,
            ]);

            // 5. Update Asset Status for active items only (ready if grade A/B without damage, maintenance if damaged or account quarantined)
            // Exchanged units in maintenance must remain in maintenance!
            $newAssetStatus = ($damageFee > 0 || $conditionGrade === 'C' || $isQuarantined) ? 'maintenance' : 'ready';
            $itemsToReturn = $booking->items->filter(fn ($it) => $it->status === 'active' || $it->status === null);
            if ($itemsToReturn->isEmpty()) {
                $itemsToReturn = $booking->items;
            }

            foreach ($itemsToReturn as $item) {
                if ($item->asset) {
                    $item->asset->update([
                        'status' => $newAssetStatus,
                        'condition_grade' => $conditionGrade,
                        'battery_health' => $data['battery_health_final'] ?? $item->asset->battery_health,
                    ]);
                }
                $item->update(['status' => 'returned']);
            }

            // 6. Record Penalty Payment if applicable
            if ($totalPenalty > 0 && !empty($data['penalty_payment_method_id'])) {
                $existingPenaltyTx = PaymentTransaction::where('booking_id', $booking->id)
                    ->where('type', 'penalty')
                    ->first();

                if (!$existingPenaltyTx) {
                    $amountToPay = $totalPenalty;
                    $cashReceived = $isCash ? (float)($data['cash_received'] ?? $amountToPay) : $amountToPay;
                    $cashChange = $isCash ? max(0, $cashReceived - $amountToPay) : 0.00;

                    $datePrefix = Carbon::now()->format('ymd');
                    $countToday = PaymentTransaction::withoutGlobalScopes()
                        ->whereDate('created_at', Carbon::today())
                        ->count() + 1;
                    $randomSuffix = strtoupper(substr(uniqid(), -4));
                    $txCode = sprintf('TX-PEN-%s-%04d-%s', $datePrefix, $countToday, $randomSuffix);
                    $receiptNumber = sprintf('REC-PEN-%s-%s-%s', $booking->affiliate->code ?? 'CTR', $datePrefix, $randomSuffix);

                    $idempotencyKey = $data['idempotency_key'] ?? null;
                    try {
                        PaymentTransaction::create([
                            'business_id' => $booking->business_id ?? app(\App\Tenancy\TenantContext::class)->getBusinessId(),
                            'branch_id' => $inspector->affiliate_id ?? $booking->affiliate_id,
                            'shift_id' => $activeShift?->id,
                            'transaction_code' => $txCode,
                            'receipt_number' => $receiptNumber,
                            'idempotency_key' => $idempotencyKey,
                            'booking_id' => $booking->id,
                            'payment_method_id' => $paymentMethod->id,
                            'processed_by' => $inspector->id,
                            'type' => 'penalty',
                            'status' => 'completed',
                            'direction' => 'inflow',
                            'amount' => $amountToPay,
                            'cash_received' => $cashReceived,
                            'cash_change' => $cashChange,
                            'notes' => !empty($data['staff_notes']) ? $data['staff_notes'] : 'Pembayaran denda keterlambatan/kerusakan unit',
                            'transacted_at' => Carbon::now(),
                        ]);
                    } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
                        if (!$idempotencyKey || !PaymentTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
                            throw $e;
                        }
                    }

                    $isRentalSettled = ((float)$booking->payments()
                        ->whereIn('type', ['dp', 'settlement', 'extension', 'exchange'])
                        ->where(function ($q) {
                            $q->whereNull('status')->orWhere('status', '!=', 'void');
                        })
                        ->sum('amount') >= (float)$booking->total_bill);

                    $booking->update([
                        'payment_status' => $isRentalSettled ? 'paid' : 'partial',
                    ]);
                }
            }

            // 6b. Record Deposit Refund if deposit exceeds penalties
            if ($depositRefunded > 0) {
                $existingRefundTx = PaymentTransaction::where('booking_id', $booking->id)
                    ->where('type', 'refund')
                    ->first();

                if (!$existingRefundTx) {
                    $cashMethod = PaymentMethod::where('code', 'CASH')->orWhere('code', 'cash')->first()
                        ?? PaymentMethod::first();

                    $datePrefix = Carbon::now()->format('ymd');
                    $countToday = PaymentTransaction::withoutGlobalScopes()
                        ->whereDate('created_at', Carbon::today())
                        ->count() + 1;
                    $randomSuffix = strtoupper(substr(uniqid(), -4));
                    $txCode = sprintf('TX-REF-%s-%04d-%s', $datePrefix, $countToday, $randomSuffix);
                    $receiptNumber = sprintf('REC-REF-%s-%s-%s', $booking->affiliate->code ?? 'CTR', $datePrefix, $randomSuffix);

                    PaymentTransaction::create([
                        'business_id' => $booking->business_id ?? app(\App\Tenancy\TenantContext::class)->getBusinessId(),
                        'branch_id' => $inspector->affiliate_id ?? $booking->affiliate_id,
                        'shift_id' => $activeShift?->id,
                        'transaction_code' => $txCode,
                        'receipt_number' => $receiptNumber,
                        'booking_id' => $booking->id,
                        'payment_method_id' => $cashMethod ? $cashMethod->id : 1,
                        'processed_by' => $inspector->id,
                        'type' => 'refund',
                        'status' => 'completed',
                        'direction' => 'outflow',
                        'amount' => $depositRefunded,
                        'cash_received' => $depositRefunded,
                        'cash_change' => 0.00,
                        'notes' => 'Pengembalian uang titipan jaminan sewa (deposit refund) saat pengembalian unit',
                        'transacted_at' => Carbon::now(),
                    ]);
                }
            }

            // 7. Immutable Security Audit Logging
            AuditLogService::log(
                $inspector,
                'booking.returned',
                'ReturnInspection',
                $returnInspection->id,
                [
                    'booking_code' => $booking->booking_code,
                    'condition_grade' => $conditionGrade,
                    'battery_health_final' => $returnInspection->battery_health_final,
                    'late_minutes' => $lateMinutes,
                    'late_fee' => $lateFee,
                    'damage_fee' => $damageFee,
                    'total_penalty' => $totalPenalty,
                    'icloud_removed' => $requiresICloud ? true : ($requiresAndroidFrp ? true : null),
                ],
                [
                    'previous_status' => 'rented',
                ],
                request()?->ip(),
                $booking->affiliate_id
            );

            return $returnInspection->load('booking.items.asset.model', 'booking.payments.paymentMethod', 'inspector');
        });
    }
}
