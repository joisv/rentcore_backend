<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PricingTier;
use App\Models\RentalExtension;
use App\Services\AuditLogService;
use App\Services\BookingService;
use App\Services\ReturnService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        protected BookingService $bookingService,
        protected ReturnService $returnService
    ) {}

    /**
     * List bookings filtered by status, date, or query.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Booking::with(['customer', 'items.asset.model', 'payments.paymentMethod']);

        if ($request->has('status')) {
            if ($request->status === 'overdue') {
                $query->where('status', 'rented')->where('end_at', '<', Carbon::now());
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_code', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
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
     * Create direct counter rental transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|string|max:30',
            'customer_email' => 'nullable|email',
            'customer_address' => 'nullable|string',
            'identity_type' => 'nullable|in:KTP,SIM,KK,PASSPORT,KARTU_PELAJAR',
            'identity_number' => 'nullable|string|max:100',
            'identity_photo' => 'nullable',
            'identity_photo_base64' => 'nullable|string',
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer|exists:assets,id',
            'duration_hours' => 'nullable|integer|min:1',
            'duration_unit' => 'nullable|string|in:hour,day,week,month',
            'duration_value' => 'nullable|integer|min:1',
            'start_at' => 'nullable|date',
            'deposit_fee' => 'nullable|numeric|min:0',
            'voucher_code' => 'nullable|string',
            'manual_discount_amount' => 'nullable|numeric|min:0',
            'manual_discount_reason' => 'nullable|string',
            'manager_pin' => 'nullable|string',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'collateral_type' => 'nullable|string|max:50',
            'collateral_storage_slot' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $booking = $this->bookingService->createCounterRental($request->all(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Transaksi sewa berhasil dibuat dan unit langsung aktif disewa.',
            'data' => $booking,
        ], 201);
    }

    /**
     * Get booking details by ID or code.
     */
    public function show(string $id): JsonResponse
    {
        $booking = Booking::with([
            'customer.identities',
            'items.asset.model',
            'payments.paymentMethod',
            'voucher',
            'extensions',
            'returnInspection.inspector',
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
        ]);

        $booking = Booking::findOrFail($id);

        if ($booking->status !== 'rented') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya rental berstatus aktif (rented) yang dapat diperpanjang.',
            ], 422);
        }

        $additionalHours = (int)$request->additional_hours;
        $previousEndAt = Carbon::parse($booking->end_at);
        $newEndAt = (clone $previousEndAt)->addHours($additionalHours);

        // Collision check: verify asset is not booked for subsequent confirmed reservation
        $assetIds = $booking->items->pluck('asset_id')->toArray();
        $collision = Booking::where('id', '!=', $booking->id)
            ->whereIn('status', ['confirmed', 'rented'])
            ->whereHas('items', function ($iq) use ($assetIds) {
                $iq->whereIn('asset_id', $assetIds);
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

        // Calculate extension fee (default: 15,000/hr)
        $additionalFee = $request->additional_fee !== null 
            ? (float)$request->additional_fee 
            : ($additionalHours * 15000);

        $extension = RentalExtension::create([
            'booking_id' => $booking->id,
            'extended_by' => $request->user()->id,
            'previous_end_at' => $previousEndAt,
            'new_end_at' => $newEndAt,
            'additional_hours' => $additionalHours,
            'additional_fee' => $additionalFee,
        ]);

        $booking->update([
            'end_at' => $newEndAt,
            'duration_hours' => $booking->duration_hours + $additionalHours,
            'total_bill' => $booking->total_bill + $additionalFee,
        ]);

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
                'booking' => $booking->fresh(['items.asset.model', 'extensions']),
                'extension' => $extension,
            ],
        ]);
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
            'checklist_results' => 'required|array',
            'checklist_results.icloud_removed' => 'nullable|boolean',
            'accessories_returned' => 'nullable|array',
            'damage_fee' => 'nullable|numeric|min:0',
            'penalty_payment_method_id' => 'nullable|exists:payment_methods,id',
            'cash_received' => 'nullable|numeric|min:0',
            'staff_notes' => 'nullable|string',
        ]);

        $booking = Booking::with('items.asset.model')->findOrFail($id);

        $inspection = $this->returnService->processReturn($booking, $request->all(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Pengembalian unit berhasil diproses dan unit dikembalikan ke status siap/perbaikan.',
            'data' => $inspection,
        ]);
    }
}
