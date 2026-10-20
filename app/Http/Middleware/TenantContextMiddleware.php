<?php

namespace App\Http\Middleware;

use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $businessId = $request->header('X-Business-Id') ?? $request->get('business_id');

        // Platform Admin bypass if explicitly accessing platform endpoints without business scope
        if ($user->isPlatformAdmin() && empty($businessId)) {
            return $next($request);
        }

        // Auto-resolve if only 1 active business and no header specified
        if (empty($businessId)) {
            $userMemberships = BusinessMember::where('user_id', $user->id)
                ->where('is_active', true)
                ->get();

            if ($userMemberships->count() === 1) {
                $businessId = $userMemberships->first()->business_id;
            } elseif ($userMemberships->isEmpty()) {
                if ($user->isSuperAdmin() || $user->affiliate_id) {
                    return $next($request);
                }
                return response()->json([
                    'success' => false,
                    'message' => 'Anda belum terdaftar dalam bisnis rental manapun. Silakan buat bisnis baru terlebih dahulu.',
                    'code' => 'NO_BUSINESS_MEMBERSHIP',
                ], 403);
            } else {
                // User has multiple businesses but none specified
                return response()->json([
                    'success' => false,
                    'message' => 'Silakan pilih bisnis terlebih dahulu (sertakan header X-Business-Id).',
                    'code' => 'BUSINESS_SELECTION_REQUIRED',
                ], 400);
            }
        }

        // Verify user membership in the requested business
        $member = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        $legacyAffiliateMatches = $user->affiliate_id 
            && Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('id', $user->affiliate_id)
                ->where('business_id', $businessId)
                ->exists();

        if (!$member && !$user->isPlatformAdmin() && !$user->isSuperAdmin() && !$legacyAffiliateMatches) {
            return response()->json([
                'success' => false,
                'message' => 'Akses bisnis ditolak. Anda bukan anggota aktif dari bisnis rental ini.',
                'code' => 'BUSINESS_ACCESS_DENIED',
            ], 403);
        }

        $business = Business::where('id', $businessId)->where('is_active', true)->first();

        if (!$business) {
            return response()->json([
                'success' => false,
                'message' => 'Bisnis rental tidak ditemukan atau telah dinonaktifkan.',
                'code' => 'BUSINESS_NOT_FOUND',
            ], 404);
        }

        // Resolve branch context (affiliate)
        $isAllBranches = $request->boolean('all_branches') || $request->get('branch_id') === 'all' || $request->get('branch_id') === '-1';
        $rawHeaderBranch = $request->header('X-Branch-Id');
        $effectiveHeaderBranch = ($rawHeaderBranch !== null && $rawHeaderBranch !== '') ? $rawHeaderBranch : null;
        $branchId = $isAllBranches ? null : ($request->get('branch_id') ?? $request->get('affiliate_id') ?? $effectiveHeaderBranch);
        $branch = null;
        $isBranchRestricted = false;

        if ($member && $member->isStaff()) {
            $allowedBranches = $member->branchMembers()->pluck('affiliate_id')->map(fn($id) => (int)$id)->toArray();
            if (empty($allowedBranches) && $user->affiliate_id) {
                $allowedBranches = [(int)$user->affiliate_id];
            }
            if (!empty($allowedBranches)) {
                $isBranchRestricted = true;
                if ($branchId && !in_array((int)$branchId, $allowedBranches)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Akses cabang ditolak. Anda tidak ditugaskan di cabang ini.',
                        'code' => 'BRANCH_ACCESS_DENIED',
                    ], 403);
                }
                $branchId = $branchId ?? $allowedBranches[0];
            }
        }

        if ($branchId) {
            // TenantContext is not bound yet at this point, so the fail-closed TenantScope on
            // Affiliate would always return null here. The query is explicitly constrained to the
            // already-verified business (and branch access was validated above), so bypassing
            // the scope is safe and required to resolve the branch context at all.
            $branch = Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('id', $branchId)
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->first();

            if (!$branch && $isBranchRestricted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cabang yang ditugaskan tidak ditemukan atau tidak aktif.',
                    'code' => 'BRANCH_ACCESS_DENIED',
                ], 403);
            }
        }

        // Bind to TenantContext singleton
        app(TenantContext::class)->set($business, $member ?? new BusinessMember(['role' => 'owner']), $branch, $isBranchRestricted);

        return $next($request);
    }
}
