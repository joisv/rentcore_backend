<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantNotification;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List notifications for the authenticated user within the active business context.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? (int)$request->header('X-Business-Id') ?? (int)$user->current_business_id;

        if (!$businessId) {
            return response()->json([
                'success' => false,
                'message' => 'Konteks bisnis tidak valid atau belum dipilih.',
            ], 422);
        }

        $query = TenantNotification::with(['affiliate:id,code,name,city'])
            ->where('business_id', $businessId)
            ->where('user_id', $user->id);

        // Filter by Category if specified
        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        // Filter by unread status
        if ($request->boolean('unread_only') || $request->get('status') === 'unread') {
            $query->unread();
        }

        // Calculate total unread count for badge
        $unreadCount = TenantNotification::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->unread()
            ->count();

        $perPage = max(5, min(50, (int)$request->get('per_page', 20)));
        $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'unread_count' => $unreadCount,
            'data' => $notifications,
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? (int)$request->header('X-Business-Id') ?? (int)$user->current_business_id;

        $notification = TenantNotification::where('id', $id)
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->firstOrFail();

        $notification->markAsRead();

        $remainingUnread = TenantNotification::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Pemberitahuan telah ditandai sebagai dibaca.',
            'unread_count' => $remainingUnread,
            'data' => $notification,
        ]);
    }

    /**
     * Mark all unread notifications for this user in the active business as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? (int)$request->header('X-Business-Id') ?? (int)$user->current_business_id;

        if (!$businessId) {
            return response()->json([
                'success' => false,
                'message' => 'Konteks bisnis tidak valid.',
            ], 422);
        }

        $affected = TenantNotification::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->unread()
            ->update(['read_at' => Carbon::now()]);

        return response()->json([
            'success' => true,
            'message' => "Semua pemberitahuan ({$affected}) telah ditandai sebagai dibaca.",
            'unread_count' => 0,
        ]);
    }
}
