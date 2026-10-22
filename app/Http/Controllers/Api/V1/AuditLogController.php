<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Retrieve paginated immutable audit logs for the active business.
     * Accessible exclusively by Store Owner or Platform Super-Admin.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? (int)$request->header('X-Business-Id');
        $isSuperAdmin = $user && ($user->isSuperAdmin() || $user->isPlatformAdmin());

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwner = $member ? $member->isOwner() : $isSuperAdmin;

        if (!$isOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Business Owner atau Super Admin yang berwenang memeriksa riwayat audit log keamanan sistem.',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $query = AuditLog::with([
            'user:id,name,email,role',
            'affiliate:id,code,name,city',
        ]);

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        // Branch filter
        if ($request->filled('branch_id') || $request->filled('affiliate_id')) {
            $branchId = $request->get('branch_id') ?? $request->get('affiliate_id');
            if ($branchId !== 'all' && $branchId !== '-1') {
                $query->where('affiliate_id', (int)$branchId);
            }
        }

        // Action filter
        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->get('action') . '%');
        }

        // Entity type filter
        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->get('entity_type'));
        }

        // User filter
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        // Date range filter
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = Carbon::parse($request->get('start_date'))->startOfDay();
            $end = Carbon::parse($request->get('end_date'))->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($request->filled('date')) {
            $start = Carbon::parse($request->get('date'))->startOfDay();
            $end = Carbon::parse($request->get('date'))->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // Free text search across action, entity_id, or IP
        if ($request->filled('search')) {
            $search = trim($request->get('search'));
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('entity_id', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = max(10, min(100, (int)$request->get('per_page', 25)));
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
}
