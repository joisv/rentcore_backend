<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Business;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    /**
     * Resolve and authorize branch context strictly within the active business.
     *
     * @return array{0: int|null, 1: JsonResponse|null}
     */
    protected function resolveAuthorizedBranchId(
        Request $request,
        ?int $businessId,
        bool $requireSpecificBranch = false,
        bool $fallbackCentralWhenUnrestricted = false
    ): array {
        $tenantContext = app(\App\Tenancy\TenantContext::class);

        if (!$businessId) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Konteks bisnis tidak ditemukan.',
                'code' => 'BUSINESS_REQUIRED',
            ], 403)];
        }

        $isAllBranches = $request->boolean('all_branches')
            || in_array((string) $request->get('branch_id'), ['all', '-1'], true)
            || in_array((string) $request->get('affiliate_id'), ['all', '-1'], true);

        $rawBranchId = $isAllBranches
            ? null
            : ($request->get('branch_id')
                ?? $request->get('affiliate_id')
                ?? $request->header('X-Branch-Id')
                ?? $tenantContext->getBranchId());

        $branchId = ($rawBranchId !== null && $rawBranchId !== '' && $rawBranchId !== 'null')
            ? (int) $rawBranchId
            : null;

        if ($requireSpecificBranch && ($isAllBranches || !$branchId)) {
            return [null, response()->json([
                'success' => false,
                'message' => 'Cabang aktif wajib dipilih untuk mengecek ketersediaan unit booking.',
                'code' => 'BRANCH_REQUIRED',
            ], 422)];
        }

        if (!$branchId && $fallbackCentralWhenUnrestricted && !$isAllBranches) {
            $defaultBranch = \App\Models\Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('is_central', 'desc')
                ->first();
            $branchId = $defaultBranch?->id ? (int) $defaultBranch->id : null;
        }

        if ($branchId) {
            $validBranch = \App\Models\Affiliate::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('id', $branchId)
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->exists();

            if (!$validBranch) {
                return [null, response()->json([
                    'success' => false,
                    'message' => 'Cabang tidak valid untuk bisnis rental ini.',
                    'code' => 'INVALID_BRANCH',
                ], 422)];
            }

            $user = $request->user();
            if ($user && $tenantContext->isStaff()) {
                $member = $tenantContext->getMember();
                if ($member) {
                    $allowedBranches = $member->branchMembers()->pluck('affiliate_id')->map(fn ($id) => (int) $id)->toArray();
                    if (!empty($allowedBranches) && !in_array($branchId, $allowedBranches, true)) {
                        return [null, response()->json([
                            'success' => false,
                            'message' => 'Akses cabang ditolak. Anda tidak ditugaskan di cabang ini.',
                            'code' => 'BRANCH_ACCESS_DENIED',
                        ], 403)];
                    }
                }
            }
        }

        return [$branchId, null];
    }

    /**
     * List available serialized assets at current branch.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        \Illuminate\Support\Facades\Log::info('GET /assets called', [
            'user_id' => $request->user()?->id,
            'user_email' => $request->user()?->email,
            'business_id' => $businessId,
            'branch_id_param' => $request->get('branch_id'),
            'affiliate_id_param' => $request->get('affiliate_id'),
            'header_branch' => $request->header('X-Branch-Id'),
            'header_business' => $request->header('X-Business-Id'),
            'status_filter' => $request->get('status'),
            'category_id' => $request->get('category_id'),
            'search' => $request->get('search'),
        ]);

        $hasScheduleWindow = $request->filled('start_at') && $request->filled('end_at');
        [$branchId, $branchError] = $this->resolveAuthorizedBranchId(
            $request,
            $businessId ? (int) $businessId : null,
            $hasScheduleWindow,
            !$hasScheduleWindow
        );
        if ($branchError) {
            return $branchError;
        }

        $query = Asset::query()
            ->select('assets.*')
            ->where('assets.business_id', $businessId)
            ->distinct()
            ->with([
                'model.category',
                'model.pricingTiers',
                'affiliate',
                'assetModel.category',
                'branch',
                'currentActiveRental.booking',
            ]);

        if ($branchId) {
            $query->where('assets.affiliate_id', $branchId);
        }

        if ($hasScheduleWindow) {
            $query->whereIn('assets.status', \App\Services\BookingAvailabilityService::RENTABLE_ASSET_STATUSES);
        } elseif ($request->has('status')) {
            $query->where('assets.status', $request->status);
        } else {
            // Exclude rejected submissions from default active inventory views
            $query->where('assets.status', '!=', 'rejected');
        }

        if ($request->has('model_id')) {
            $query->where('assets.model_id', $request->model_id);
        }

        if ($request->has('category_id')) {
            $query->whereHas('model', function ($mq) use ($request) {
                $mq->where('category_id', $request->category_id);
            });
        }

        if ($request->has('is_addon') && $request->input('is_addon') !== null && $request->input('is_addon') !== '') {
            $isAddonFilter = filter_var($request->input('is_addon'), FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('model', function ($mq) use ($isAddonFilter) {
                if ($isAddonFilter) {
                    $mq->addons();
                } else {
                    $mq->devices();
                }
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('assets.asset_code', 'like', "%{$search}%")
                  ->orWhere('assets.serial_number', 'like', "%{$search}%")
                  ->orWhere('assets.imei_1', 'like', "%{$search}%")
                  ->orWhere('assets.imei_2', 'like', "%{$search}%")
                  ->orWhereHas('model', function ($mq) use ($search) {
                      $mq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderBy('assets.created_at', 'desc')
              ->orderBy('assets.id', 'desc');

        if ($hasScheduleWindow) {
            $startAt = \Carbon\Carbon::parse($request->start_at)->utc();
            $endAt = \Carbon\Carbon::parse($request->end_at)->utc();
            $availabilityService = app(\App\Services\BookingAvailabilityService::class);
            $collection = $query->get()->unique('id')->values()->map(function (Asset $asset) use ($availabilityService, $startAt, $endAt) {
                $eval = $availabilityService->evaluateAssetWindowAvailability($asset, $startAt, $endAt);
                $asset->setAttribute('is_available_for_schedule', $eval['is_available']);
                $asset->setAttribute('is_available_for_window', $eval['is_available']);
                $asset->setAttribute('conflict_reason', $eval['conflict_reason']);
                $asset->setAttribute('conflict_start', $eval['conflict_start']);
                $asset->setAttribute('conflict_end', $eval['conflict_end']);
                return $asset;
            });
            if ($request->boolean('available_only', false)) {
                $collection = $collection->filter(fn (Asset $a) => (bool) $a->getAttribute('is_available_for_window'))->values();
            }
            return response()->json([
                'success' => true,
                'data' => $collection,
            ]);
        }

        if ($request->boolean('all', false)) {
            $assets = $query->get()->unique('id')->values();
            return response()->json([
                'success' => true,
                'data' => $assets,
            ]);
        }

        $perPage = max(1, min(50, (int) $request->get('per_page', 20)));
        $assets = $query->paginate($perPage);

        if ($assets->currentPage() > $assets->lastPage() && $assets->lastPage() > 0) {
            $assets = $query->paginate($perPage, ['*'], 'page', $assets->lastPage());
        }

        // Verify and ensure collection strictly contains unique unit IDs
        $assets->setCollection($assets->getCollection()->unique('id')->values());

        return response()->json([
            'success' => true,
            'data' => $assets,
        ]);
    }

    /**
     * Get specific asset details.
     */
    public function show(int $id): JsonResponse
    {
        $asset = Asset::with([
            'model.category',
            'model.pricingTiers',
            'affiliate',
            'assetModel.category',
            'branch',
            'currentActiveRental.booking',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }

    /**
     * List catalog models with active pricing packages and optional availability counts for [start_at, end_at].
     */
    public function models(Request $request): JsonResponse
    {
        if ($request->filled('start_at') && $request->filled('end_at')) {
            return $this->modelAvailability($request);
        }

        $businessId = app(\App\Tenancy\TenantContext::class)->getBusinessId();
        $query = AssetModel::with(['category', 'pricingTiers' => function ($q) {
            $q->where('is_active', true);
        }])
        ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
        ->where('is_active', true);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        $models = $query->get();

        return response()->json([
            'success' => true,
            'data' => $models,
        ]);
    }

    /**
     * Return per-model available slot counts for a given [start_at, end_at] window at the active branch.
     */
    public function modelAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'branch_id' => 'nullable|max:20',
            'affiliate_id' => 'nullable|max:20',
            'category_id' => 'nullable|integer|exists:asset_categories,id',
            'search' => 'nullable|string|max:100',
            'all_branches' => 'nullable',
        ]);

        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        [$branchId, $branchError] = $this->resolveAuthorizedBranchId(
            $request,
            $businessId ? (int) $businessId : null,
            true,
            false
        );
        if ($branchError) {
            return $branchError;
        }

        $startAt = \Carbon\Carbon::parse($request->start_at)->utc();
        $endAt = \Carbon\Carbon::parse($request->end_at)->utc();
        $availabilityService = app(\App\Services\BookingAvailabilityService::class);

        $models = $availabilityService->getAvailableModelsForBranch(
            (int) $branchId,
            $startAt,
            $endAt,
            (int) $businessId,
            $request->filled('category_id') ? (int) $request->category_id : null,
            $request->get('search')
        );

        return response()->json([
            'success' => true,
            'data' => $models,
            'meta' => [
                'branch_id' => (int) $branchId,
                'start_at' => $startAt->toIso8601String(),
                'end_at' => $endAt->toIso8601String(),
            ],
        ]);
    }

    /**
     * Register a new serialized device asset at outlet.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $business = $businessId ? Business::find($businessId) : null;
        $isOwner = ($member && $member->isOwner())
            || ($business && $user && (string) $business->owner_id === (string) $user->id)
            || ($user && ($user->isSuperAdmin() || $user->isPlatformAdmin()));
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->isAdmin()) : $isOwner;
        $registrationPolicy = $business ? $business->getBranchUnitRegistrationPolicy() : 'self_service';

        // Enforce owner-configured branch unit registration policy for non-owners
        if (!$isOwner && $registrationPolicy === 'owner_only') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner yang dapat mendaftarkan unit baru.',
                'code' => 'OWNER_ONLY_UNIT_REGISTRATION',
            ], 403);
        }

        $affiliateId = $request->get('affiliate_id') 
            ?? $request->header('X-Branch-Id') 
            ?? $tenantContext->getBranchId();

        if (empty($affiliateId)) {
            $defaultBranch = $businessId
                ? \App\Models\Affiliate::where('business_id', $businessId)
                    ->where('is_active', true)
                    ->orderBy('is_central', 'desc')
                    ->first()
                : null;
            $affiliateId = $defaultBranch?->id ?? $user->affiliate_id;
        }

        // Branch authorization check for staff: staff can only register into their own assigned branch
        if (!$isOwnerOrAdmin && $user && !$user->isSuperAdmin()) {
            $allowedBranches = $member ? $member->branchMembers()->pluck('affiliate_id')->map(fn($b) => (int)$b)->toArray() : [];
            if (empty($allowedBranches) && $user->affiliate_id) {
                $allowedBranches = [(int)$user->affiliate_id];
            }
            if ($affiliateId && !in_array((int)$affiliateId, $allowedBranches, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses cabang ditolak. Anda tidak berwenang mendaftarkan unit di cabang ini.',
                    'code' => 'BRANCH_ACCESS_DENIED',
                ], 403);
            }
            if (empty($allowedBranches)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun Anda belum ditugaskan ke cabang manapun untuk mendaftarkan unit inventaris.',
                    'code' => 'NO_BRANCH_ASSIGNMENT',
                ], 403);
            }
        }

        if ($affiliateId && !$user->isSuperAdmin()) {
            $validBranch = \App\Models\Affiliate::where('id', $affiliateId)
                ->where('business_id', $businessId)
                ->exists();
            if (!$validBranch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cabang tidak valid untuk bisnis rental ini.',
                    'code' => 'INVALID_BRANCH',
                ], 422);
            }
        }

        $model = AssetModel::withoutGlobalScopes()->find($request->model_id);
        $serialRule = ($model && $model->has_serial_number === false)
            ? 'nullable'
            : 'required';

        $validated = $request->validate([
            'model_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('asset_models', 'id')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'asset_code' => [
                'required',
                'string',
                'max:50',
                \Illuminate\Validation\Rule::unique('assets', 'asset_code')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'serial_number' => [
                $serialRule,
                'string',
                'max:100',
                \Illuminate\Validation\Rule::unique('assets', 'serial_number')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'imei_1' => 'nullable|string|max:50',
            'imei_2' => 'nullable|string|max:50',
            'condition_grade' => 'nullable|in:A,B,C',
            'battery_health' => 'nullable|integer|min:0|max:100',
            'hourly_late_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $requiresApproval = !$isOwner && $registrationPolicy === 'require_owner_approval';
        $initialStatus = $requiresApproval ? 'pending_approval' : 'ready';

        $asset = Asset::create([
            'business_id' => $businessId,
            'affiliate_id' => $affiliateId,
            'model_id' => $validated['model_id'],
            'asset_code' => strtoupper(trim($validated['asset_code'])),
            'serial_number' => !empty($validated['serial_number']) ? strtoupper(trim($validated['serial_number'])) : null,
            'imei_1' => $validated['imei_1'] ?? null,
            'imei_2' => $validated['imei_2'] ?? null,
            'status' => $initialStatus,
            'condition_grade' => $validated['condition_grade'] ?? 'A',
            'battery_health' => (isset($validated['battery_health']) && $validated['battery_health'] !== '') ? (int)$validated['battery_health'] : null,
            'hourly_late_fee' => ($request->exists('hourly_late_fee') && $validated['hourly_late_fee'] !== null && $validated['hourly_late_fee'] !== '')
                ? (float) $validated['hourly_late_fee']
                : null,
            'notes' => $validated['notes'] ?? null,
            'submitted_by' => $user?->id,
            'approved_by' => $requiresApproval ? null : $user?->id,
            'approved_at' => $requiresApproval ? null : Carbon::now(),
        ]);

        if ($requiresApproval && $user) {
            app(NotificationService::class)->notifyUnitApprovalRequested($asset, $user);
        }

        AuditLogService::log(
            $user,
            $requiresApproval ? 'asset.approval_requested' : 'asset.created',
            'Asset',
            $asset->id,
            [
                'asset_code' => $asset->asset_code,
                'serial_number' => $asset->serial_number,
                'model_id' => $asset->model_id,
                'status' => $asset->status,
            ],
            null,
            $request->ip(),
            $affiliateId
        );

        return response()->json([
            'success' => true,
            'message' => $requiresApproval
                ? 'Unit baru berhasil diajukan dan menunggu persetujuan Owner.'
                : 'Perangkat elektronik berhasil didaftarkan ke inventaris.',
            'data' => $asset->load(['model.category', 'affiliate', 'submitter:id,name']),
        ], 201);
    }

    /**
     * Owner approves a branch-submitted unit (`pending_approval` -> `ready`).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $asset = Asset::with(['model.category', 'affiliate', 'submitter:id,name'])->findOrFail($id);
        $assetBusinessId = (int) ($asset->business_id ?? $businessId);
        $business = $assetBusinessId ? Business::find($assetBusinessId) : null;
        $member = $tenantContext->getMember() ?? ($assetBusinessId && $user ? $user->getBusinessMembership($assetBusinessId) : null);

        $isOwner = ($member && $member->isOwner())
            || ($business && $user && (string) $business->owner_id === (string) $user->id)
            || ($user && ($user->isSuperAdmin() || $user->isPlatformAdmin()));

        if (!$isOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner yang berwenang menyetujui pengajuan unit baru.',
                'code' => 'OWNER_REQUIRED',
            ], 403);
        }

        if ($asset->status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'Unit ini tidak sedang menunggu persetujuan.',
                'code' => 'INVALID_ASSET_STATUS',
            ], 422);
        }

        $asset->update([
            'status' => 'ready',
            'approved_by' => $user->id,
            'approved_at' => Carbon::now(),
            'rejection_reason' => null,
        ]);

        app(NotificationService::class)->notifyUnitApproved($asset, $user);

        AuditLogService::log(
            $user,
            'asset.approved',
            'Asset',
            $asset->id,
            [
                'asset_code' => $asset->asset_code,
                'submitted_by' => $asset->submitted_by,
                'status' => 'ready',
            ],
            ['status' => 'pending_approval'],
            $request->ip(),
            $asset->affiliate_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Unit baru berhasil disetujui dan kini berstatus Siap Sewa.',
            'data' => $asset->fresh(['model.category', 'affiliate', 'submitter:id,name']),
        ]);
    }

    /**
     * Owner rejects a branch-submitted unit (`pending_approval` -> `rejected`).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $asset = Asset::with(['model.category', 'affiliate', 'submitter:id,name'])->findOrFail($id);
        $assetBusinessId = (int) ($asset->business_id ?? $businessId);
        $business = $assetBusinessId ? Business::find($assetBusinessId) : null;
        $member = $tenantContext->getMember() ?? ($assetBusinessId && $user ? $user->getBusinessMembership($assetBusinessId) : null);

        $isOwner = ($member && $member->isOwner())
            || ($business && $user && (string) $business->owner_id === (string) $user->id)
            || ($user && ($user->isSuperAdmin() || $user->isPlatformAdmin()));

        if (!$isOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner yang berwenang menolak pengajuan unit baru.',
                'code' => 'OWNER_REQUIRED',
            ], 403);
        }

        if ($asset->status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'Unit ini tidak sedang menunggu persetujuan.',
                'code' => 'INVALID_ASSET_STATUS',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $reason = trim((string) ($validated['reason'] ?? $validated['rejection_reason'] ?? ''));
        $reasonOrNull = $reason !== '' ? $reason : null;

        $asset->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => Carbon::now(),
            'rejection_reason' => $reasonOrNull,
        ]);

        app(NotificationService::class)->notifyUnitRejected($asset, $user, $reasonOrNull);

        AuditLogService::log(
            $user,
            'asset.rejected',
            'Asset',
            $asset->id,
            [
                'asset_code' => $asset->asset_code,
                'submitted_by' => $asset->submitted_by,
                'status' => 'rejected',
                'rejection_reason' => $reasonOrNull,
            ],
            ['status' => 'pending_approval'],
            $request->ip(),
            $asset->affiliate_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan unit baru telah ditolak.',
            'data' => $asset->fresh(['model.category', 'affiliate', 'submitter:id,name']),
        ]);
    }

    /**
     * Update asset condition, identifiers, model, notes, or maintenance status.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $asset->business_id ?? $tenantContext->getBusinessId();
        $user = $request->user();

        // 1. Enforce branch authorization for staff
        if ($user && $tenantContext->isStaff()) {
            $member = $tenantContext->getMember();
            if ($member) {
                $allowedBranches = $member->branchMembers()->pluck('affiliate_id')->map(fn($b) => (int)$b)->toArray();
                if (!empty($allowedBranches) && !in_array((int)$asset->affiliate_id, $allowedBranches)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Akses cabang ditolak. Anda tidak berwenang mengedit unit di cabang ini.',
                        'code' => 'BRANCH_ACCESS_DENIED',
                    ], 403);
                }
            }
        }

        // 2. Prevent reassignment of business_id or affiliate_id through edit request
        if ($request->has('business_id') && (int)$request->business_id !== (int)$asset->business_id) {
            return response()->json([
                'success' => false,
                'message' => 'Bisnis pemilik unit tidak dapat diubah.',
                'code' => 'BUSINESS_REASSIGNMENT_FORBIDDEN',
            ], 422);
        }

        if ($request->has('affiliate_id') && (int)$request->affiliate_id !== (int)$asset->affiliate_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cabang penempatan unit tidak dapat diubah secara langsung melalui form edit. Gunakan fitur Mutasi Stok Antar-Cabang.',
                'code' => 'BRANCH_REASSIGNMENT_FORBIDDEN',
            ], 422);
        }

        // 3. Validation
        $validated = $request->validate([
            'model_id' => [
                'nullable',
                Rule::exists('asset_models', 'id')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'asset_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('assets', 'asset_code')->ignore($asset->id)->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'serial_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('assets', 'serial_number')->ignore($asset->id)->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'imei_1' => 'nullable|string|max:50',
            'imei_2' => 'nullable|string|max:50',
            'status' => 'nullable|in:ready,reserved,rented,maintenance,in_transit,retired,lost',
            'condition_grade' => 'nullable|in:A,B,C',
            'battery_health' => 'nullable|integer|min:0|max:100',
            'hourly_late_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        // 4. Guard model_id change
        if (isset($validated['model_id']) && (int)$validated['model_id'] !== (int)$asset->model_id) {
            if ($asset->bookingItems()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat mengubah model katalog unit karena unit memiliki riwayat booking/transaksi rental.',
                    'code' => 'MODEL_CHANGE_RESTRICTED',
                ], 422);
            }
            if (in_array($asset->status, ['rented', 'reserved', 'in_transit'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat mengubah model katalog saat unit sedang aktif disewa, dipesan, atau dalam proses mutasi.',
                    'code' => 'ACTIVE_STATE_RESTRICTED',
                ], 422);
            }
        }

        // 5. Guard status change
        if (isset($validated['status']) && $validated['status'] !== $asset->status) {
            if ($asset->status === 'rented') {
                return response()->json([
                    'success' => false,
                    'message' => 'Status unit tidak dapat diubah secara manual saat unit sedang aktif disewa pelanggan. Selesaikan pengembalian (return) terlebih dahulu.',
                    'code' => 'RENTED_STATUS_LOCKED',
                ], 422);
            }
            if ($asset->status === 'in_transit') {
                return response()->json([
                    'success' => false,
                    'message' => 'Status unit tidak dapat diubah secara manual saat unit dalam proses mutasi antar cabang. Selesaikan atau batalkan mutasi terlebih dahulu.',
                    'code' => 'IN_TRANSIT_STATUS_LOCKED',
                ], 422);
            }
            if ($asset->status === 'pending_approval') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unit yang menunggu persetujuan hanya dapat diubah statusnya melalui persetujuan Owner.',
                    'code' => 'PENDING_APPROVAL_LOCKED',
                ], 422);
            }
        }

        // Format uppercase identifiers
        if (isset($validated['asset_code'])) {
            $validated['asset_code'] = strtoupper(trim($validated['asset_code']));
        }
        if (isset($validated['serial_number'])) {
            $validated['serial_number'] = !empty($validated['serial_number']) ? strtoupper(trim($validated['serial_number'])) : null;
        }

        return DB::transaction(function () use ($request, $asset, $validated) {
            $oldValues = $asset->only([
                'model_id',
                'asset_code',
                'serial_number',
                'imei_1',
                'imei_2',
                'status',
                'condition_grade',
                'battery_health',
                'hourly_late_fee',
                'notes',
            ]);

            $updatePayload = array_filter($validated, fn($v) => $v !== null);
            if ($request->exists('hourly_late_fee')) {
                $updatePayload['hourly_late_fee'] = ($validated['hourly_late_fee'] !== null && $validated['hourly_late_fee'] !== '')
                    ? (float) $validated['hourly_late_fee']
                    : null;
            }
            $asset->update($updatePayload);

            AuditLogService::log(
                $request->user(),
                'asset.updated',
                'Asset',
                $asset->id,
                $asset->only([
                    'model_id',
                    'asset_code',
                    'serial_number',
                    'imei_1',
                    'imei_2',
                    'status',
                    'condition_grade',
                    'battery_health',
                    'hourly_late_fee',
                    'notes',
                ]),
                $oldValues,
                $request->ip(),
                $asset->affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Data perangkat berhasil diperbarui.',
                'data' => $asset->fresh(['model.category', 'affiliate']),
            ]);
        });
    }

    /**
     * Check if specific assets or model units are available for requested interval.
     */
    public function checkAvailability(Request $request, ?int $id = null): JsonResponse
    {
        if ($id && !$request->has('asset_ids')) {
            $request->merge(['asset_ids' => [$id]]);
        }

        $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'integer|exists:assets,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
        ]);

        $startAt = \Carbon\Carbon::parse($request->start_at)->utc();
        $endAt = \Carbon\Carbon::parse($request->end_at)->utc();
        $availabilityService = app(\App\Services\BookingAvailabilityService::class);

        $results = [];
        $allAvailable = true;

        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        [$branchId, $branchError] = $this->resolveAuthorizedBranchId(
            $request,
            $businessId ? (int) $businessId : null,
            true,
            false
        );
        if ($branchError) {
            return $branchError;
        }

        $assets = Asset::with('model')
            ->where('business_id', $businessId)
            ->whereIn('id', $request->asset_ids)
            ->get();
        foreach ($assets as $asset) {
            $eval = $availabilityService->evaluateAssetWindowAvailability($asset, $startAt, $endAt);
            $isAvailable = $eval['is_available'];
            $conflictReason = $eval['conflict_reason'];
            if ($branchId && (int)$asset->affiliate_id !== (int)$branchId) {
                $isAvailable = false;
                $conflictReason = 'Unit tidak berada di cabang yang dipilih.';
            }
            if (!$isAvailable) {
                $allAvailable = false;
            }
            $results[] = [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'model_name' => $asset->model->name,
                'status' => $asset->status,
                'affiliate_id' => $asset->affiliate_id,
                'is_available' => $isAvailable,
                'is_available_for_window' => $isAvailable,
                'conflict_reason' => $conflictReason,
                'conflict_start' => $eval['conflict_start'],
                'conflict_end' => $eval['conflict_end'],
            ];
        }

        return response()->json([
            'success' => true,
            'all_available' => $allAvailable,
            'data' => [
                'is_available' => $allAvailable,
                'assets' => $results,
            ],
        ]);
    }

    /**
     * Delete or archive an individual inventory asset unit safely.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $asset->business_id ?? $tenantContext->getBusinessId();
        $user = $request->user();

        // 1. Enforce role authorization: Only Owner or Admin can delete units
        $member = $tenantContext->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->isAdmin()) : ($user && ($user->isSuperAdmin() || $user->isPlatformAdmin()));

        if (!$isOwnerOrAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang memiliki wewenang untuk menghapus unit inventaris.',
                'code' => 'FORBIDDEN_ACTION',
            ], 403);
        }

        // 2. Branch authorization check if user is branch-constrained
        if ($user && $tenantContext->isStaff()) {
            $allowedBranches = $member ? $member->branchMembers()->pluck('affiliate_id')->map(fn($b) => (int)$b)->toArray() : [];
            if (!empty($allowedBranches) && !in_array((int)$asset->affiliate_id, $allowedBranches)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses cabang ditolak. Anda tidak berwenang mengelola unit di cabang ini.',
                    'code' => 'BRANCH_ACCESS_DENIED',
                ], 403);
            }
        }

        // 3. Operational status checks
        if ($asset->status === 'rented' || $asset->bookingItems()->whereHas('booking', fn($q) => $q->whereIn('status', ['active', 'rented', 'confirmed', 'overdue']))->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Unit sedang dalam masa sewa aktif dan tidak dapat dihapus.',
                'code' => 'ASSET_RENTED_ACTIVE',
            ], 422);
        }

        if ($asset->status === 'in_transit') {
            return response()->json([
                'success' => false,
                'message' => 'Unit sedang dalam proses mutasi transfer antar cabang dan tidak dapat dihapus.',
                'code' => 'ASSET_IN_TRANSIT',
            ], 422);
        }

        if ($asset->status === 'reserved') {
            return response()->json([
                'success' => false,
                'message' => 'Unit telah direservasi untuk pesanan sewa mendatang dan tidak dapat dihapus.',
                'code' => 'ASSET_RESERVED',
            ], 422);
        }

        // 4. Historical integrity check (Bookings, Transfers, Exchanges)
        $hasHistoricalBookings = $asset->bookingItems()->exists();
        $hasHistoricalTransfers = \App\Models\InventoryTransfer::where('asset_id', $asset->id)->exists();
        $hasHistoricalExchanges = $asset->exchangesAsOld()->exists() || $asset->exchangesAsNew()->exists();

        $hasHistory = $hasHistoricalBookings || $hasHistoricalTransfers || $hasHistoricalExchanges;

        if ($hasHistory) {
            // Cannot hard delete without breaking database foreign key constraints & financial history.
            // Safely transition status to 'retired' (archived)
            $oldStatus = $asset->status;
            $asset->update([
                'status' => 'retired',
                'notes' => trim(($asset->notes ?? '') . " [Diarsipkan dari inventaris aktif oleh {$user->name} pada " . now()->format('Y-m-d H:i') . "]"),
            ]);

            AuditLogService::log(
                $user,
                'asset.retired',
                'Asset',
                $asset->id,
                ['status' => 'retired', 'reason' => 'historical_integrity_preservation'],
                ['status' => $oldStatus],
                $request->ip(),
                $asset->affiliate_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Unit memiliki riwayat transaksi sewa/mutasi, sehingga status unit berhasil diarsipkan menjadi "Retired" untuk menjaga integritas data akuntansi.',
                'data' => [
                    'id' => $asset->id,
                    'status' => 'retired',
                    'action' => 'archived',
                ],
            ]);
        }

        // 5. If completely pristine (no transaction history), execute clean hard delete
        $oldData = $asset->toArray();
        $affiliateId = $asset->affiliate_id;
        $asset->delete();

        AuditLogService::log(
            $user,
            'asset.deleted',
            'Asset',
            $id,
            null,
            $oldData,
            $request->ip(),
            $affiliateId
        );

        return response()->json([
            'success' => true,
            'message' => 'Unit inventaris berhasil dihapus permanen.',
            'data' => [
                'id' => $id,
                'action' => 'deleted',
            ],
        ]);
    }
}
