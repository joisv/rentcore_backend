<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Carbon\Carbon;
use Exception;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    /**
     * Validate a voucher for redemption at checkout.
     * Enforces active state, date range, affiliate scope, and minimum transaction amount.
     */
    public function validateVoucher(string $code, float $subtotal, ?int $customerId = null, ?int $affiliateId = null): array
    {
        $voucher = Voucher::where('code', trim($code))->first();

        if (!$voucher) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Kode voucher tidak ditemukan.'],
            ]);
        }

        if (!$voucher->is_active) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher sudah tidak aktif.'],
            ]);
        }

        $now = Carbon::now();
        if ($now->lt($voucher->start_at)) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Periode promo voucher belum dimulai (mulai: ' . $voucher->start_at->format('d/m/Y') . ').'],
            ]);
        }

        if ($now->gt($voucher->end_at)) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher telah kedaluwarsa pada ' . $voucher->end_at->format('d/m/Y H:i') . '.'],
            ]);
        }

        // Branch exclusivity check
        if ($voucher->affiliate_id && $affiliateId && $voucher->affiliate_id !== $affiliateId) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher ini hanya berlaku khusus di cabang ' . ($voucher->affiliate->name ?? 'lain') . '.'],
            ]);
        }

        // Minimum Transaction Amount Threshold Guard
        if ($subtotal < (float)$voucher->min_transaction_amount) {
            throw ValidationException::withMessages([
                'voucher_code' => [
                    'Total transaksi sewa (Rp ' . number_format($subtotal, 0, ',', '.') . 
                    ') belum mencapai batas minimal belanja voucher (Rp ' . 
                    number_format((float)$voucher->min_transaction_amount, 0, ',', '.') . ').'
                ],
            ]);
        }

        // Total usage limit check
        if ($voucher->usage_limit_total && $voucher->usage_count >= $voucher->usage_limit_total) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Kuota penggunaan voucher ini telah habis.'],
            ]);
        }

        // Per-customer usage limit check
        if ($customerId) {
            $customerUsages = VoucherUsage::where('voucher_id', $voucher->id)
                ->where('customer_id', $customerId)
                ->count();

            if ($customerUsages >= $voucher->usage_limit_per_customer) {
                throw ValidationException::withMessages([
                    'voucher_code' => ['Pelanggan ini sudah mencapai batas maksimal penggunaan voucher ini (' . $voucher->usage_limit_per_customer . 'x).'],
                ]);
            }
        }

        // Calculate discount percentage
        $nominalDiscount = $subtotal * ((float)$voucher->discount_percentage / 100);

        // Apply discount cap if set
        if ($voucher->max_discount_cap && $nominalDiscount > (float)$voucher->max_discount_cap) {
            $nominalDiscount = (float)$voucher->max_discount_cap;
        }

        $finalPayable = max(0, $subtotal - $nominalDiscount);

        return [
            'valid' => true,
            'voucher_id' => $voucher->id,
            'code' => $voucher->code,
            'name' => $voucher->name,
            'discount_percentage' => (float)$voucher->discount_percentage,
            'min_transaction_amount' => (float)$voucher->min_transaction_amount,
            'calculated_discount' => round($nominalDiscount, 2),
            'final_payable' => round($finalPayable, 2),
        ];
    }

    /**
     * Generate thermal ESC/POS payload & structure for printing physical voucher slips with 2D Barcode.
     */
    public function generateThermalSlipPayload(Voucher $voucher, ?Affiliate $affiliate = null): array
    {
        $shopName = 'RENTCORE GADGETS';
        $branchName = $affiliate ? $affiliate->name : ($voucher->affiliate ? $voucher->affiliate->name : 'Semua Cabang RentCore');
        $validUntil = $voucher->end_at->format('d M Y');
        $minBelanja = 'Rp ' . number_format((float)$voucher->min_transaction_amount, 0, ',', '.');
        $diskonText = number_format((float)$voucher->discount_percentage, 0) . '% OFF';

        // Human readable preview lines for mobile UI
        $textPreview = [
            'center' => [
                $shopName,
                $branchName,
                '================================',
                'VOUCHER DISKON SPESIAL',
                '*** ' . $diskonText . ' ***',
                '================================',
            ],
            'details' => [
                'Kode Voucher' => $voucher->code,
                'Min. Belanja' => $minBelanja,
                'Berlaku s/d' => $validUntil,
            ],
            'barcode' => [
                'type' => $voucher->barcode_type, // 'qr_code' or 'datamatrix'
                'data' => $voucher->code,
                'size' => 6, // ESC/POS module size
            ],
            'footer' => [
                '* Tunjukkan slip voucher fisik ini atau scan QR Code di kasir saat transaksi sewa.',
                'Terima kasih telah mempercayai RentCore!',
            ]
        ];

        // ESC/POS Command stream bytes (hex representation)
        // ESC @ (init), ESC a 1 (center align), GS ( k (QR code), LF, ESC d 3 (feed 3 lines), GS V 0 (cut)
        $escposCommands = [
            'init' => "\x1B\x40",
            'center_align' => "\x1B\x61\x01",
            'bold_on' => "\x1B\x45\x01",
            'bold_off' => "\x1B\x45\x00",
            'cut_paper' => "\x1D\x56\x00",
            'qr_data' => $voucher->code,
        ];

        return [
            'voucher_code' => $voucher->code,
            'barcode_format' => $voucher->barcode_type,
            'qr_payload' => $voucher->code,
            'preview' => $textPreview,
            'escpos_metadata' => [
                'feed_lines' => 3,
                'auto_cut' => true,
                'module_size' => 6,
            ],
        ];
    }
}
