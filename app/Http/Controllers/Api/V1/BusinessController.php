<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BranchMember;
use App\Models\CashDrawerShift;
use App\Models\User;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
                'slogan' => $biz->slogan,
                'slug' => $biz->slug,
                'phone' => $biz->phone,
                'address' => $biz->address,
                'city' => $biz->city,
                'currency' => $biz->currency,
                'business_categories' => $biz->business_categories ?? [],
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
            'business_categories' => 'nullable|array',
            'business_categories.*' => 'string|max:50',
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
                'business_categories' => $validated['business_categories'] ?? null,
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
            'slogan' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'business_categories' => 'nullable|array',
            'business_categories.*' => 'string|max:50',
        ]);

        $business->update(array_filter($validated, fn($v) => $v !== null));

        return response()->json([
            'success' => true,
            'message' => 'Informasi bisnis berhasil diperbarui.',
            'data' => $business,
        ]);
    }

    /**
     * Get rental rules & policies for the active business.
     */
    public function getRentalRules(Request $request, ?int $id = null): JsonResponse
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

        $business = Business::findOrFail($businessId);

        return response()->json([
            'success' => true,
            'data' => $business->getRentalRules(),
        ]);
    }

    /**
     * Update rental rules & policies for the active business (Owner/Admin only).
     */
    public function updateRentalRules(Request $request, ?int $id = null): JsonResponse
    {
        $context = app(TenantContext::class);
        $businessId = $id ? (int)$id : $context->getBusinessId();
        $user = $request->user();

        $member = BusinessMember::where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ((!$member || !$member->isAdmin()) && !$user->isPlatformAdmin() && !$user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang berhak mengubah kebijakan rental bisnis.',
            ], 403);
        }

        $business = Business::findOrFail($businessId);

        $validated = $request->validate([
            'id_document_limit_enabled' => 'nullable|boolean',
            'max_primary_devices_per_id' => 'nullable|integer|min:1',
            'addons_enabled' => 'nullable|boolean',
            'max_addons_per_rental' => 'nullable|integer|min:1',
            'allow_unlimited_addons' => 'nullable|boolean',
        ]);

        $updates = [];
        if (array_key_exists('id_document_limit_enabled', $validated)) {
            $updates['id_document_limit_enabled'] = (bool)$validated['id_document_limit_enabled'];
        }
        if (array_key_exists('max_primary_devices_per_id', $validated)) {
            $updates['max_primary_devices_per_id'] = (int)$validated['max_primary_devices_per_id'];
        }
        if (array_key_exists('addons_enabled', $validated)) {
            $updates['addons_enabled'] = (bool)$validated['addons_enabled'];
        }
        if (!empty($validated['allow_unlimited_addons'])) {
            $updates['max_addons_per_rental'] = null;
        } elseif (array_key_exists('max_addons_per_rental', $validated)) {
            $updates['max_addons_per_rental'] = $validated['max_addons_per_rental'] !== null
                ? (int)$validated['max_addons_per_rental']
                : null;
        }

        $business->update($updates);

        AuditLogService::log(
            $user,
            'business.rental_rules_updated',
            'Business',
            $business->id,
            $updates,
            null,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Kebijakan sewa dan add-on berhasil diperbarui.',
            'data' => $business->getRentalRules(),
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

        return DB::transaction(function () use ($validated, $businessId, $request) {
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

            // Acquire exclusive lock on target user to prevent concurrent multi-employment race conditions
            User::where('id', $user->id)->lockForUpdate()->first();

            // Constraint: a user may hold at most one active staff/employment membership in an independently owned business
            $hasOtherStaffEmployment = BusinessMember::join('businesses', 'businesses.id', '=', 'business_members.business_id')
                ->where('business_members.user_id', $user->id)
                ->where('business_members.is_active', true)
                ->where('businesses.owner_id', '!=', $user->id)
                ->where('business_members.business_id', '!=', $businessId)
                ->exists();

            if ($hasOtherStaffEmployment) {
                throw ValidationException::withMessages([
                    'email' => [
                        'Pengguna ini sudah terdaftar sebagai staf aktif di bisnis rental lain. ' .
                        'Berdasarkan kebijakan sistem, satu akun hanya dapat aktif bekerja pada satu bisnis rental pihak lain dalam satu waktu.'
                    ],
                ]);
            }

            // Check existing membership in this business
            $existing = BusinessMember::where('business_id', $businessId)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                if ($existing->is_active) {
                    throw ValidationException::withMessages([
                        'email' => ['Pengguna ini sudah terdaftar sebagai anggota aktif bisnis ini.'],
                    ]);
                }

                // Reactivate previously resigned or deactivated membership
                $existing->update([
                    'role' => $validated['role'],
                    'is_active' => true,
                    'joined_at' => Carbon::now(),
                    'invited_by' => $request->user()->id,
                ]);
                $member = $existing;
            } else {
                $member = BusinessMember::create([
                    'business_id' => $businessId,
                    'user_id' => $user->id,
                    'role' => $validated['role'],
                    'is_active' => true,
                    'invited_by' => $request->user()->id,
                    'joined_at' => Carbon::now(),
                ]);
            }

            // Sync branch assignments
            BranchMember::where('business_member_id', $member->id)->delete();
            if (!empty($validated['branch_ids'])) {
                foreach ($validated['branch_ids'] as $branchId) {
                    BranchMember::create([
                        'business_member_id' => $member->id,
                        'affiliate_id' => $branchId,
                    ]);
                }
            }

            AuditLogService::log(
                $request->user(),
                'business_member.added',
                'BusinessMember',
                (string)$member->id,
                [
                    'target_user' => $user->name,
                    'email' => $user->email,
                    'role' => $member->role,
                    'reactivated' => (bool)$existing,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Staf berhasil didaftarkan ke bisnis rental.',
                'data' => $member->load(['user', 'branchMembers.branch']),
            ], 201);
        });
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

    /**
     * Reassign a staff member to another branch.
     */
    public function reassignBranch(Request $request, int $id, int $memberId): JsonResponse
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
                'message' => 'Hanya Owner atau Admin yang berwenang memindahkan cabang staf.',
            ], 403);
        }

        $validated = $request->validate([
            'from_branch_id' => 'nullable|integer|exists:affiliates,id',
            'to_branch_id' => 'required|integer|exists:affiliates,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $targetMember = BusinessMember::where('business_id', $businessId)
            ->where('id', $memberId)
            ->firstOrFail();

        $toBranch = Affiliate::where('business_id', $businessId)
            ->where('id', $validated['to_branch_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $fromBranchId = $validated['from_branch_id'] ?? null;
        if (!$fromBranchId) {
            $firstBranch = $targetMember->branchMembers()->first();
            $fromBranchId = $firstBranch?->affiliate_id;
        }

        if ($fromBranchId && (int)$fromBranchId === (int)$toBranch->id) {
            throw ValidationException::withMessages([
                'to_branch_id' => ['Cabang tujuan tidak boleh sama dengan cabang asal.'],
            ]);
        }

        // Operational guardrail: check if user has an active unclosed shift at fromBranchId
        if ($fromBranchId) {
            $hasActiveShift = CashDrawerShift::where('branch_id', $fromBranchId)
                ->where('opened_by', $targetMember->user_id)
                ->where('status', 'open')
                ->exists();

            if ($hasActiveShift) {
                throw ValidationException::withMessages([
                    'from_branch_id' => [
                        'Staf masih memiliki shift kasir aktif yang belum ditutup pada cabang asal. ' .
                        'Tutup shift kasir terlebih dahulu sebelum memindahkan penugasan staf.'
                    ],
                ]);
            }
        }

        return DB::transaction(function () use ($targetMember, $fromBranchId, $toBranch, $user, $validated) {
            if ($fromBranchId) {
                BranchMember::where('business_member_id', $targetMember->id)
                    ->where('affiliate_id', $fromBranchId)
                    ->delete();
            } else {
                BranchMember::where('business_member_id', $targetMember->id)->delete();
            }

            BranchMember::firstOrCreate([
                'business_member_id' => $targetMember->id,
                'affiliate_id' => $toBranch->id,
            ]);

            AuditLogService::log(
                $user,
                'business_member.branch_reassigned',
                'BusinessMember',
                (string)$targetMember->id,
                [
                    'target_user' => $targetMember->user?->name ?? $targetMember->user_id,
                    'from_branch_id' => $fromBranchId,
                    'to_branch_id' => $toBranch->id,
                    'to_branch_name' => $toBranch->name,
                    'reason' => $validated['reason'] ?? 'Mutasi/penugasan cabang oleh admin',
                ],
                null,
                request()?->ip(),
                $toBranch->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Penugasan cabang staf berhasil dipindahkan ke ' . $toBranch->name . '.',
                'data' => $targetMember->fresh(['user', 'branchMembers.branch']),
            ]);
        });
    }

    /**
     * Remove branch assignment from a staff member.
     */
    public function removeBranchMember(Request $request, int $id, int $memberId, int $branchId): JsonResponse
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
                'message' => 'Hanya Owner atau Admin yang berwenang mencabut penugasan cabang staf.',
            ], 403);
        }

        $targetMember = BusinessMember::where('business_id', $businessId)
            ->where('id', $memberId)
            ->firstOrFail();

        $hasActiveShift = CashDrawerShift::where('branch_id', $branchId)
            ->where('opened_by', $targetMember->user_id)
            ->where('status', 'open')
            ->exists();

        if ($hasActiveShift) {
            throw ValidationException::withMessages([
                'branch_id' => [
                    'Staf masih memiliki shift kasir aktif di cabang ini. Tutup shift kasir terlebih dahulu.'
                ],
            ]);
        }

        BranchMember::where('business_member_id', $targetMember->id)
            ->where('affiliate_id', $branchId)
            ->delete();

        AuditLogService::log(
            $user,
            'business_member.branch_removed',
            'BusinessMember',
            (string)$targetMember->id,
            [
                'target_user' => $targetMember->user?->name ?? $targetMember->user_id,
                'removed_branch_id' => $branchId,
            ],
            null,
            request()?->ip(),
            $branchId
        );

        return response()->json([
            'success' => true,
            'message' => 'Penugasan cabang berhasil dicabut.',
            'data' => $targetMember->fresh(['user', 'branchMembers.branch']),
        ]);
    }

    /**
     * Update a business member's role (admin / staff).
     */
    public function updateMemberRole(Request $request, int $id, int $memberId): JsonResponse
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
                'message' => 'Hanya Owner atau Admin yang berwenang mengubah peran staf.',
            ], 403);
        }

        $validated = $request->validate([
            'role' => 'required|in:admin,staff',
        ]);

        $targetMember = BusinessMember::where('business_id', $businessId)
            ->where('id', $memberId)
            ->firstOrFail();

        if ($targetMember->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Peran Owner bisnis tidak dapat diubah.',
            ], 422);
        }

        $targetMember->update(['role' => $validated['role']]);

        AuditLogService::log(
            $user,
            'business_member.role_updated',
            'BusinessMember',
            (string)$targetMember->id,
            [
                'target_user' => $targetMember->user?->name ?? $targetMember->user_id,
                'new_role' => $validated['role'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Peran anggota berhasil diperbarui menjadi ' . ucfirst($validated['role']) . '.',
            'data' => $targetMember->fresh(['user', 'branchMembers.branch']),
        ]);
    }

    /**
     * Deactivate a member from the business.
     */
    public function removeMember(Request $request, int $id, int $memberId): JsonResponse
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
                'message' => 'Hanya Owner atau Admin yang berwenang menonaktifkan staf dari bisnis.',
            ], 403);
        }

        $targetMember = BusinessMember::where('business_id', $businessId)
            ->where('id', $memberId)
            ->firstOrFail();

        if ($targetMember->isOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Owner bisnis tidak dapat dinonaktifkan.',
            ], 422);
        }

        // Guardrail: check active shifts across any branch of this business
        $businessBranchIds = Affiliate::where('business_id', $businessId)->pluck('id');
        $hasActiveShift = CashDrawerShift::whereIn('branch_id', $businessBranchIds)
            ->where('opened_by', $targetMember->user_id)
            ->where('status', 'open')
            ->exists();

        if ($hasActiveShift) {
            throw ValidationException::withMessages([
                'member' => [
                    'Staf masih memiliki shift kasir yang sedang aktif. Tutup shift kasir terlebih dahulu.'
                ],
            ]);
        }

        return DB::transaction(function () use ($targetMember, $user) {
            BranchMember::where('business_member_id', $targetMember->id)->delete();
            $targetMember->update(['is_active' => false]);

            AuditLogService::log(
                $user,
                'business_member.deactivated',
                'BusinessMember',
                (string)$targetMember->id,
                [
                    'target_user' => $targetMember->user?->name ?? $targetMember->user_id,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Anggota staf berhasil dinonaktifkan dari bisnis rental.',
            ]);
        });
    }

    /**
     * Set or update a manager override PIN for a member.
     */
    public function setManagerPin(Request $request, int $id, int $memberId): JsonResponse
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
                'message' => 'Hanya Owner atau Admin yang berwenang mengatur PIN Manager.',
            ], 403);
        }

        $validated = $request->validate([
            'pin' => 'required|string|regex:/^\d{6}$/',
        ]);

        $targetMember = BusinessMember::with('user')
            ->where('business_id', $businessId)
            ->where('id', $memberId)
            ->firstOrFail();

        $targetUser = $targetMember->user;
        $targetUser->manager_override_pin = Hash::make($validated['pin']);
        $targetUser->save();

        AuditLogService::log(
            $user,
            'security.manager_pin_updated',
            'User',
            (string)$targetUser->id,
            ['target_user' => $targetUser->name]
        );

        return response()->json([
            'success' => true,
            'message' => 'PIN Manager untuk ' . $targetUser->name . ' berhasil diperbarui.',
        ]);
    }

    /**
     * Revoke a manager override PIN for a member.
     */
    public function revokeManagerPin(Request $request, int $id, int $memberId): JsonResponse
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
                'message' => 'Hanya Owner atau Admin yang berwenang mencabut PIN Manager.',
            ], 403);
        }

        $targetMember = BusinessMember::with('user')
            ->where('business_id', $businessId)
            ->where('id', $memberId)
            ->firstOrFail();

        $targetUser = $targetMember->user;
        $targetUser->manager_override_pin = null;
        $targetUser->save();

        AuditLogService::log(
            $user,
            'security.manager_pin_revoked',
            'User',
            (string)$targetUser->id,
            ['target_user' => $targetUser->name]
        );

        return response()->json([
            'success' => true,
            'message' => 'PIN Manager untuk ' . $targetUser->name . ' berhasil dicabut.',
        ]);
    }
}
