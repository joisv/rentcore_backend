<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierController extends Controller
{
    /**
     * Get active payment methods.
     */
    public function paymentMethods(): JsonResponse
    {
        $methods = PaymentMethod::where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
    }

    /**
     * POS Cashier: Process payment for a rental transaction or penalty.
     * Enforces multi-tenant isolation, row locking against double payments,
     * authoritative amount due recalculation, cash change calculation,
     * and immutable financial ledger writing.
     */
    public function processPayment(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|integer|exists:bookings,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'payment_type' => 'nullable|in:settlement,penalty,dp,extension',
            'amount' => 'nullable|numeric|min:0',
            'cash_received' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $booking = Booking::withoutGlobalScopes()
            ->with(['customer', 'items.asset.model', 'payments.paymentMethod', 'returnInspection', 'affiliate'])
            ->findOrFail($request->booking_id);

        // Enforce franchise multi-tenant isolation
        if ($user && $user->affiliate_id && !$user->isSuperAdmin() && $booking->affiliate_id !== $user->affiliate_id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses untuk memproses pembayaran cabang lain.',
            ], 403);
        }

        $type = $request->get('payment_type', 'settlement');
        $paymentMethod = PaymentMethod::findOrFail($request->payment_method_id);
        $isCash = strtoupper($paymentMethod->code) === 'CASH';

        return DB::transaction(function () use ($booking, $paymentMethod, $isCash, $type, $request, $user) {
            // Re-fetch booking with row-level pessimistic lock
            $lockedBooking = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if ($type === 'settlement') {
                $alreadyPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension'])
                    ->sum('amount');
                $remainingDue = max(0, (float)$lockedBooking->total_bill - $alreadyPaid);

                if ($remainingDue <= 0 && $lockedBooking->payment_status === 'paid') {
                    throw ValidationException::withMessages([
                        'booking' => ['Transaksi sewa ini sudah lunas sebelumnya.'],
                    ]);
                }

                $amountToPay = $request->filled('amount') ? min((float)$request->amount, $remainingDue) : $remainingDue;
            } elseif ($type === 'penalty') {
                $inspection = $lockedBooking->returnInspection;
                if (!$inspection || (float)$inspection->total_penalty <= 0) {
                    throw ValidationException::withMessages([
                        'booking' => ['Tidak ada tagihan denda untuk transaksi ini.'],
                    ]);
                }
                $penaltyPaid = (float)$lockedBooking->payments()->where('type', 'penalty')->sum('amount');
                $remainingPenalty = max(0, (float)$inspection->total_penalty - $penaltyPaid);

                if ($remainingPenalty <= 0) {
                    throw ValidationException::withMessages([
                        'booking' => ['Denda untuk transaksi ini sudah lunas.'],
                    ]);
                }

                $amountToPay = $request->filled('amount') ? min((float)$request->amount, $remainingPenalty) : $remainingPenalty;
            } else {
                $amountToPay = (float)($request->amount ?? $lockedBooking->total_bill);
            }

            // Cash validation & change calculation
            if ($isCash) {
                if (!isset($request->cash_received)) {
                    throw ValidationException::withMessages([
                        'cash_received' => ['Jumlah uang tunai yang diterima wajib diisi.'],
                    ]);
                }
                $cashReceived = (float)$request->cash_received;
                if ($cashReceived < $amountToPay) {
                    throw ValidationException::withMessages([
                        'cash_received' => [
                            'Jumlah uang tunai yang diterima (Rp ' . number_format($cashReceived, 0, ',', '.') . 
                            ') kurang dari total tagihan (Rp ' . number_format($amountToPay, 0, ',', '.') . ').'
                        ],
                    ]);
                }
                $cashChange = max(0, $cashReceived - $amountToPay);
            } else {
                $cashReceived = $amountToPay;
                $cashChange = 0.00;
            }

            $datePrefix = Carbon::now()->format('ymd');
            $txCode = sprintf('TX-%s-%04d', $datePrefix, PaymentTransaction::count() + 1);
            $receiptNumber = sprintf('REC-%s-%s-%04d', $booking->affiliate->code ?? 'PST', $datePrefix, $booking->id);

            $paymentTx = PaymentTransaction::create([
                'transaction_code' => $txCode,
                'receipt_number' => $receiptNumber,
                'booking_id' => $booking->id,
                'payment_method_id' => $paymentMethod->id,
                'processed_by' => $user->id,
                'type' => $type,
                'amount' => $amountToPay,
                'cash_received' => $cashReceived,
                'cash_change' => $cashChange,
                'notes' => $request->notes ?? ($type === 'penalty' ? 'Pelunasan denda via Kasir POS' : 'Pembayaran sewa via Kasir POS'),
                'transacted_at' => Carbon::now(),
            ]);

            // Update booking payment status
            if ($type === 'settlement') {
                $newTotalPaid = (float)$lockedBooking->payments()
                    ->whereIn('type', ['dp', 'settlement', 'extension'])
                    ->sum('amount');
                $newPaymentStatus = ($newTotalPaid >= (float)$lockedBooking->total_bill) ? 'paid' : 'partial';
                $lockedBooking->update(['payment_status' => $newPaymentStatus]);
            }

            // Security Audit Log
            AuditLogService::log(
                $user,
                'cashier.payment_processed',
                'PaymentTransaction',
                $txCode,
                [
                    'booking_code' => $booking->booking_code,
                    'amount' => $amountToPay,
                    'method' => $paymentMethod->name,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'type' => $type,
                ],
                null,
                request()?->ip(),
                $booking->affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran kasir POS berhasil diproses.',
                'data' => [
                    'payment' => $paymentTx->load('paymentMethod'),
                    'booking' => $lockedBooking->fresh(['customer', 'payments.paymentMethod', 'items.asset.model']),
                    'amount_due' => $amountToPay,
                    'cash_received' => $cashReceived,
                    'cash_change' => $cashChange,
                    'receipt_number' => $receiptNumber,
                ],
            ], 201);
        });
    }

    /**
     * Get thermal receipt data for a payment transaction.
     */
    public function receipt(string $id): JsonResponse
    {
        $user = request()->user();
        $tx = PaymentTransaction::with([
            'paymentMethod',
            'booking.customer',
            'booking.items.asset.model',
            'booking.affiliate',
            'booking.voucher',
            'processedBy',
        ])->where('id', $id)->orWhere('receipt_number', $id)->firstOrFail();

        // Franchise multi-tenant isolation
        if ($user && $user->affiliate_id && !$user->isSuperAdmin() && $tx->booking->affiliate_id !== $user->affiliate_id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses struk cabang lain.',
            ], 403);
        }

        $affiliate = $tx->booking->affiliate;
        $items = $tx->booking->items->map(function ($item) {
            return [
                'name' => $item->asset->model->name ?? 'Perangkat Apple',
                'asset_code' => $item->asset->asset_code ?? '',
                'price' => (float)$item->unit_price,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'receipt_number' => $tx->receipt_number,
                'transaction_code' => $tx->transaction_code,
                'outlet_name' => $affiliate->name ?? 'RentCore Central Hub',
                'outlet_address' => $affiliate->address ?? 'Pusat Rental Elektronik',
                'outlet_phone' => $affiliate->phone ?? '0812-3456-7890',
                'transacted_at' => $tx->transacted_at,
                'cashier_name' => $tx->processedBy->name ?? 'Kasir RentCore',
                'customer_name' => $tx->booking->customer->name ?? 'Pelanggan',
                'customer_phone' => $tx->booking->customer->phone ?? '',
                'customer_address' => $tx->booking->customer->address ?? '',
                'booking_code' => $tx->booking->booking_code,
                'duration_hours' => $tx->booking->duration_hours,
                'items' => $items,
                'subtotal' => (float)$tx->booking->subtotal_amount,
                'discount_amount' => (float)$tx->booking->discount_amount,
                'voucher_code' => $tx->booking->voucher->code ?? null,
                'total_amount' => (float)$tx->amount,
                'payment_method' => $tx->paymentMethod->name ?? 'Tunai',
                'cash_received' => (float)$tx->cash_received,
                'cash_change' => (float)$tx->cash_change,
            ],
        ]);
    }

    /**
     * Get closing shift summary for today or given date.
     */
    public function closingSummary(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->toDateString());
        $start = Carbon::parse($date)->startOfDay();
        $end = Carbon::parse($date)->endOfDay();

        $user = $request->user();
        $affiliateId = $user ? $user->affiliate_id : null;

        $query = PaymentTransaction::with(['paymentMethod', 'booking'])
            ->whereBetween('transacted_at', [$start, $end]);

        // Enforce franchise multi-tenant isolation
        if ($affiliateId && (!$user || !$user->isSuperAdmin())) {
            $query->whereHas('booking', function ($bq) use ($affiliateId) {
                $bq->where('affiliate_id', $affiliateId);
            });
        }

        $transactions = $query->get();

        $breakdown = [];
        $totalRevenue = 0.00;
        $totalTransactions = $transactions->count();

        foreach ($transactions as $tx) {
            $methodName = $tx->paymentMethod->name ?? 'Lainnya';
            if (!isset($breakdown[$methodName])) {
                $breakdown[$methodName] = [
                    'count' => 0,
                    'total' => 0.00,
                ];
            }
            $breakdown[$methodName]['count']++;
            $breakdown[$methodName]['total'] += (float)$tx->amount;
            $totalRevenue += (float)$tx->amount;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date,
                'total_revenue' => (float)$totalRevenue,
                'total_transactions' => (int)$totalTransactions,
                'breakdown_by_method' => (object)$breakdown,
                'recent_transactions' => $transactions->take(10),
            ],
        ]);
    }
}
