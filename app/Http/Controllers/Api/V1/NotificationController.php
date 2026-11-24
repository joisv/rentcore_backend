<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\BusinessMember;
use App\Models\TenantNotification;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Notification types representing personal resignation decisions addressed to a user
     * that must remain readable even after the user's membership in that business is revoked.
     */
    private const RESIGNATION_DECISION_TYPES = [
        'staff.resignation_approved',
        'staff.resignation_rejected',
        'resign_approved',
        'resign_rejected',
    ];

    /**
     * Resolve an authorized active business ID for the request if one exists.
     * Returns null when the user no longer has active membership in the requested business
     * (for instance right after resignation approval).
     */
    private function resolveAuthorizedBusinessId(Request $request, $user): ?int
    {
        $tenantContext = app(TenantContext::class);
        $rawBusinessId = $tenantContext->getBusinessId()
            ?: (int) $request->header('X-Business-Id')
            ?: (int) $request->get('business_id')
            ?: (int) ($user->current_business_id ?? 0);

        if ($rawBusinessId <= 0) {
            return null;
        }

        if ($user->isPlatformAdmin() || $user->isSuperAdmin()) {
            return $rawBusinessId;
        }

        $isActiveMember = BusinessMember::where('business_id', $rawBusinessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if ($isActiveMember) {
            return $rawBusinessId;
        }

        if ($user->affiliate_id) {
            $legacyMatches = Affiliate::withoutGlobalScopes()
                ->where('id', $user->affiliate_id)
                ->where('business_id', $rawBusinessId)
                ->exists();
            if ($legacyMatches) {
                return $rawBusinessId;
            }
        }

        return null;
    }

    /**
     * Scope notifications strictly to the authenticated user's active business PLUS
     * personal resignation decision notifications addressed directly to user_id.
     */
    private function scopedNotificationsQuery(string $userId, ?int $activeBusinessId): Builder
    {
        return TenantNotification::with(['affiliate:id,code,name,city'])
            ->where('user_id', $userId)
            ->where(function (Builder $q) use ($activeBusinessId) {
                if ($activeBusinessId !== null) {
                    $q->where('business_id', $activeBusinessId)
                        ->orWhereIn('type', self::RESIGNATION_DECISION_TYPES);
                } else {
                    $q->whereIn('type', self::RESIGNATION_DECISION_TYPES);
                }
            });
    }

    /**
     * List notifications for the authenticated user within the active business context
     * plus personal resignation decision notifications regardless of deactivated membership.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeBusinessId = $this->resolveAuthorizedBusinessId($request, $user);

        $query = $this->scopedNotificationsQuery($user->id, $activeBusinessId);

        if ($request->filled('category') && $request->category !== 'all') {
            $query->where('category', $request->category);
        }

        if ($request->boolean('unread_only') || $request->get('status') === 'unread') {
            $query->unread();
        }

        $unreadCount = $this->scopedNotificationsQuery($user->id, $activeBusinessId)
            ->unread()
            ->count();

        $perPage = max(5, min(50, (int) $request->get('per_page', 20)));
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
        $activeBusinessId = $this->resolveAuthorizedBusinessId($request, $user);

        $notification = $this->scopedNotificationsQuery($user->id, $activeBusinessId)
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        $remainingUnread = $this->scopedNotificationsQuery($user->id, $activeBusinessId)
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
     * Mark all unread notifications for this user in scope as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeBusinessId = $this->resolveAuthorizedBusinessId($request, $user);

        $affected = $this->scopedNotificationsQuery($user->id, $activeBusinessId)
            ->unread()
            ->update(['read_at' => Carbon::now()]);

        return response()->json([
            'success' => true,
            'message' => "Semua pemberitahuan ({$affected}) telah ditandai sebagai dibaca.",
            'unread_count' => 0,
        ]);
    }
}
