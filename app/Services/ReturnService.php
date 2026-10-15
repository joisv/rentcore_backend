<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\PricingTier;
use App\Models\ReturnInspection;
use App\Models\User;
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
            if ($booking->status !== 'rented') {
                throw ValidationException::withMessages([
                    'booking' => ["Booking {$booking->booking_code} tidak dalam status sewa aktif (status saat ini: {$booking->status})."],
                ]);
            }

            // 1. Mandatory Critical Security Guard: iCloud / Google FRP Cleared (only for devices requiring it)
            $requiresFrp = true;
            if ($booking->items->isNotEmpty()) {
                $requiresFrp = false;
                foreach ($booking->items as $item) {
                    $model = $item->asset?->model;
                    if ($model) {
                        $isFrpBrand = in_array(strtolower($model->brand ?? ''), ['apple', 'samsung', 'google', 'xiaomi', 'oppo', 'vivo']);
                        $isFrpName = (bool)preg_match('/(iphone|ipad|galaxy|pixel)/i', $model->name ?? '');
                        if ($model->requires_frp_check || $isFrpBrand || $isFrpName) {
                            $requiresFrp = true;
                            break;
                        }
                    }
                }
            }

            $checklist = $data['checklist_results'] ?? [];
            if ($requiresFrp && empty($checklist['icloud_removed'])) {
                throw ValidationException::withMessages([
                    'checklist' => ['Akun iCloud / Apple ID / Google FRP wajib dipastikan telah dikeluarkan (Sign Out) sebelum pengembalian dapat diproses.'],
                ]);
            }

            $returnedAt = !empty($data['returned_at']) ? Carbon::parse($data['returned_at']) : Carbon::now();
            $expectedEndAt = Carbon::parse($booking->end_at);

            // 2. Late Minutes & Grace Period Penalty Formula
            // Formula: max(0, ceil((late_minutes - 60) / 60)) * hourly_rate
            $lateMinutes = 0;
            $lateFee = 0.00;

            if ($returnedAt->gt($expectedEndAt)) {
                $lateMinutes = $expectedEndAt->diffInMinutes($returnedAt);

                if ($lateMinutes > 60) {
                    $billableHours = (int)ceil(($lateMinutes - 60) / 60);

                    // Fetch hourly rate from model pricing tier or default to 15,000 IDR/hr
                    $firstItem = $booking->items()->first();
                    $hourlyRate = 15000.00;
                    if ($firstItem && $firstItem->asset) {
                        $tier = PricingTier::where('model_id', $firstItem->asset->model_id)->first();
                        if ($tier && (float)$tier->extension_hourly_rate > 0) {
                            $hourlyRate = (float)$tier->extension_hourly_rate;
                        }
                    }

                    $lateFee = $billableHours * $hourlyRate;
                }
            }

            $damageFee = (float)($data['damage_fee'] ?? 0.00);
            $totalPenalty = $lateFee + $damageFee;
            $depositRefunded = max(0, (float)$booking->deposit_fee - $totalPenalty);

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
            $booking->update([
                'status' => 'returned',
            ]);

            // 5. Update Asset Status (ready if grade A/B without damage, maintenance if damaged)
            $newAssetStatus = ($damageFee > 0 || $conditionGrade === 'C') ? 'maintenance' : 'ready';
            foreach ($booking->items as $item) {
                if ($item->asset) {
                    $item->asset->update([
                        'status' => $newAssetStatus,
                        'condition_grade' => $conditionGrade,
                        'battery_health' => $data['battery_health_final'] ?? $item->asset->battery_health,
                    ]);
                }
            }

            // 6. Record Penalty Payment if applicable
            if ($totalPenalty > 0 && !empty($data['penalty_payment_method_id'])) {
                $paymentMethod = PaymentMethod::findOrFail($data['penalty_payment_method_id']);
                $datePrefix = Carbon::now()->format('ymd');
                $txCode = sprintf('TX-PEN-%s-%04d', $datePrefix, PaymentTransaction::count() + 1);
                $receiptNumber = sprintf('REC-PEN-%s-%04d', $datePrefix, $booking->id);

                PaymentTransaction::create([
                    'business_id' => $booking->business_id ?? app(\App\Tenancy\TenantContext::class)->getBusinessId(),
                    'transaction_code' => $txCode,
                    'receipt_number' => $receiptNumber,
                    'booking_id' => $booking->id,
                    'payment_method_id' => $paymentMethod->id,
                    'processed_by' => $inspector->id,
                    'type' => 'penalty',
                    'amount' => $totalPenalty,
                    'cash_received' => $data['cash_received'] ?? $totalPenalty,
                    'cash_change' => 0.00,
                    'notes' => 'Pembayaran denda keterlambatan/kerusakan unit',
                    'transacted_at' => Carbon::now(),
                ]);
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
                    'icloud_removed' => $requiresFrp ? true : null,
                ],
                [
                    'previous_status' => 'rented',
                ],
                request()?->ip(),
                $booking->affiliate_id
            );

            return $returnInspection->load('booking.items.asset.model', 'inspector');
        });
    }
}
