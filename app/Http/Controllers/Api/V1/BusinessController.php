<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    /**
     * List all rental businesses the authenticated user belongs to.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $memberships = BusinessMember::with(['business.branches'])
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $businesses = $memberships->map(function ($membership) {
            $biz = $membership->business;
            return [
                'id' => $biz->id,
                'uuid' => $biz->uuid,
                'name' => $biz->name,
                'slug' => $biz->slug,
                'phone' => $biz->phone,
                'city' => $biz->city,
                'currency' => $biz->currency,
                'role' => $membership->role,
                'joined_at' => $membership->joined_at,
                // Only branches this member is authorized for (central first). Prevents the
                // client from defaulting to a branch the tenant middleware will reject.
                'branches' => $membership->serializeAccessibleBranches(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $businesses,
        ]);
    }

    /**
     * Register a new rental business (Onboarding).
     * The authenticated user becomes the Owner.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'initial_branch_name' => 'nullable|string|max:150',
            'initial_branch_code' => 'nullable|string|max:10',
        ]);

        $user = $request->user();

        $business = DB::transaction(function () use ($validated, $user, $request) {
            $business = Business::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? $user->phone,
                'email' => $validated['email'] ?? $user->email,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'currency' => $validated['currency'] ?? 'IDR',
                'owner_id' => $user->id,
                'is_active' => true,
            ]);

            // Assign user as Owner
            $member = BusinessMember::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'is_active' => true,
                'joined_at' => Carbon::now(),
            ]);

            // Create default central branch outlet
            $branchCode = !empty($validated['initial_branch_code']) 
                ? strtoupper(trim($validated['initial_branch_code']))
                : strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $business->name), 0, 3) ?: 'PST');

            $branchName = $validated['initial_branch_name'] ?? ($business->name . ' Pusat');

            $branch = Affiliate::create([
                'business_id' => $business->id,
                'code' => $branchCode,
                'name' => $branchName,
                'slug' => Str::slug($business->name . '-' . $branchCode),
                'phone' => $business->phone,
                'address' => $business->address,
                'city' => $business->city,
                'is_central' => true,
                'is_active' => true,
            ]);

            // Assign owner to the branch
            BranchMember::create([
                'business_member_id' => $member->id,
                'affiliate_id' => $branch->id,
            ]);

            AuditLogService::log(
                $user,
                'business.created',
                'Business',
                $business->id,
                ['name' => $business->name, 'branch' => $branch->name],
                null,
                $request->ip(),
                $branch->id
            );

            return $business->load('branches');
        });

        return response()->json([
            'success' => true,
            'message' => 'Bisnis rental dan cabang utama berhasil didaftarkan.',
            'data' => $business,
        ], 201);
    }

    /**
     * Show business details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $isMember = BusinessMember::where('business_id', $id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (!$isMember && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses bisnis ditolak. Anda bukan anggota aktif dari bisnis ini.',
            ], 403);
        }

        $business = Business::with(['branches', 'members.user'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $business,
        ]);
    }

    /**
     * List branches of a business.
     */
    public function branches(Request $request, ?int $id = null): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ? (int)$id : $context->getBusinessId();
        $user = $request->user();

        $isMember = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (!$isMember && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda bukan anggota aktif dari bisnis ini.',
            ], 403);
        }

        $branches = Affiliate::where('business_id', $businessId)
            ->orderBy('is_central', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $branches,
        ]);
    }

    /**
     * Update business details (Owner or Admin only).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $context = app(TenantContext::class);
        $businessId = $id ?: $context->getBusinessId();

        $member = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ((!$member || !$member->isAdmin()) && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang berhak mengubah informasi bisnis.',
            ], 403);
        }

        $business = Business::findOrFail($businessId);

        $validated = $request->validate([
            'name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
        ]);

        $business->update(array_filter($validated, fn($v) => $v !== null));

        return response()->json([
            'success' => true,
            'message' => 'Informasi bisnis berhasil diperbarui.',
            'data' => $business,
        ]);
    }

    /**
     * List members/staff of the active business.
     */
    public function members(Request $request, ?int $id = null): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ? (int)$id : $context->getBusinessId();
        $user = $request->user();

        $isMember = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (!$isMember && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Anda bukan anggota aktif dari bisnis ini.',
            ], 403);
        }

        $members = BusinessMember::with(['user', 'branchMembers.branch'])
            ->where('business_id', $businessId)
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }

    /**
     * Invite/add a member to the business.
     */
    public function addMember(Request $request, ?int $id = null): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ? (int)$id : $context->getBusinessId();
        $user = $request->user();

        $currentMember = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ((!$currentMember || !$currentMember->isAdmin()) && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang berwenang menambahkan staf.',
            ], 403);
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'nullable|string|max:150',
            'role' => 'required|in:admin,staff',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|exists:affiliates,id',
        ]);

        // Security: Strictly verify that all assigned branches belong to THIS business
        if (!empty($validated['branch_ids'])) {
            $validBranchIds = Affiliate::where('business_id', $businessId)
                ->whereIn('id', $validated['branch_ids'])
                ->pluck('id')
                ->toArray();

            if (count($validBranchIds) !== count($validated['branch_ids'])) {
                throw ValidationException::withMessages([
                    'branch_ids' => ['Satu atau lebih cabang yang dipilih tidak valid untuk bisnis rental ini.'],
                ]);
            }
        }

        // Check if user exists, or create a pending placeholder user
        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            $user = User::create([
                'email' => $validated['email'],
                'name' => $validated['name'] ?? explode('@', $validated['email'])[0],
                'password' => null,
                'role' => 'staff',
                'is_active' => true,
                'is_platform_admin' => false,
            ]);
        }

        // Check existing membership
        $existing = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => ['Pengguna ini sudah terdaftar sebagai anggota bisnis.'],
            ]);
        }

        $member = BusinessMember::create([
            'business_id' => $businessId,
            'user_id' => $user->id,
            'role' => $validated['role'],
            'is_active' => true,
            'invited_by' => $request->user()->id,
            'joined_at' => Carbon::now(),
        ]);

        if (!empty($validated['branch_ids'])) {
            foreach ($validated['branch_ids'] as $branchId) {
                BranchMember::create([
                    'business_member_id' => $member->id,
                    'affiliate_id' => $branchId,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Staf berhasil didaftarkan ke bisnis rental.',
            'data' => $member->load(['user', 'branchMembers.branch']),
        ], 201);
    }

    /**
     * Add a branch to the active business.
     */
    public function addBranch(Request $request, ?int $id = null): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ? (int)$id : $context->getBusinessId();
        $user = $request->user();

        $currentMember = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ((!$currentMember || !$currentMember->isAdmin()) && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang berwenang membuat cabang baru.',
            ], 403);
        }

        $business = Business::findOrFail($businessId);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'required|string|max:10',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'royalty_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $code = strtoupper(trim($validated['code']));

        if (Affiliate::where('business_id', $businessId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['Kode cabang sudah digunakan di bisnis Anda.'],
            ]);
        }

        $branch = Affiliate::create([
            'business_id' => $businessId,
            'code' => $code,
            'name' => $validated['name'],
            'slug' => Str::slug($business->name . '-' . $code),
            'phone' => $validated['phone'] ?? $business->phone,
            'address' => $validated['address'] ?? $business->address,
            'city' => $validated['city'] ?? $business->city,
            'is_central' => false,
            'royalty_percentage' => $validated['royalty_percentage'] ?? 0.00,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cabang baru berhasil ditambahkan.',
            'data' => $branch,
        ], 201);
    }
}
