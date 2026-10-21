<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
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

        $query = Asset::with(['model.category', 'model.pricingTiers', 'affiliate']);

        $branchId = $request->get('branch_id') 
            ?? $request->get('affiliate_id') 
            ?? $request->header('X-Branch-Id') 
            ?? $tenantContext->getBranchId();

        if (!$branchId && $businessId && !$request->boolean('all_branches')) {
            $defaultBranch = \App\Models\Affiliate::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('is_central', 'desc')
                ->first();
            $branchId = $defaultBranch?->id;
        }

        if ($branchId) {
            if ($businessId) {
                $validBranch = \App\Models\Affiliate::where('id', $branchId)
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

            $user = $request->user();
            if ($user && $tenantContext->isStaff()) {
                $member = $tenantContext->getMember();
                if ($member) {
                    $allowedBranches = $member->branchMembers()->pluck('affiliate_id')->toArray();
                    if (!empty($allowedBranches) && !in_array((int)$branchId, $allowedBranches)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Akses cabang ditolak. Anda tidak ditugaskan di cabang ini.',
                            'code' => 'BRANCH_ACCESS_DENIED',
                        ], 403);
                    }
                }
            }

            $query->where('affiliate_id', $branchId);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('model_id')) {
            $query->where('model_id', $request->model_id);
        }

        if ($request->has('category_id')) {
            $query->whereHas('model', function ($mq) use ($request) {
                $mq->where('category_id', $request->category_id);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('asset_code', 'like', "%{$search}%")
                  ->orWhere('serial_number', 'like', "%{$search}%")
                  ->orWhereHas('model', function ($mq) use ($search) {
                      $mq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->boolean('all', false)) {
            $assets = $query->get();
            return response()->json([
                'success' => true,
                'data' => $assets,
            ]);
        }

        $perPage = (int) $request->get('per_page', 20);
        $assets = $query->paginate($perPage);

        if ($assets->currentPage() > $assets->lastPage() && $assets->lastPage() > 0) {
            $assets = $query->paginate($perPage, ['*'], 'page', $assets->lastPage());
        }

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
        $asset = Asset::with(['model.category', 'model.pricingTiers', 'affiliate'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }

    /**
     * List catalog models with active pricing packages.
     */
    public function models(Request $request): JsonResponse
    {
        $query = AssetModel::with(['category', 'pricingTiers' => function ($q) {
            $q->where('is_active', true);
        }])
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
     * Register a new serialized device asset at outlet.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $businessId = app(\App\Tenancy\TenantContext::class)->getBusinessId();

        $affiliateId = $request->get('affiliate_id') 
            ?? $request->header('X-Branch-Id') 
            ?? app(\App\Tenancy\TenantContext::class)->getBranchId();

        if (empty($affiliateId)) {
            $defaultBranch = \App\Models\Affiliate::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('is_central', 'desc')
                ->first();
            $affiliateId = $defaultBranch?->id ?? $user->affiliate_id;
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
                'required',
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
            'notes' => 'nullable|string',
        ]);

        $asset = Asset::create([
            'business_id' => $businessId,
            'affiliate_id' => $affiliateId,
            'model_id' => $validated['model_id'],
            'asset_code' => strtoupper(trim($validated['asset_code'])),
            'serial_number' => strtoupper(trim($validated['serial_number'])),
            'imei_1' => $validated['imei_1'] ?? null,
            'imei_2' => $validated['imei_2'] ?? null,
            'status' => 'ready',
            'condition_grade' => $validated['condition_grade'] ?? 'A',
            'battery_health' => $validated['battery_health'] ?? 100,
            'notes' => $validated['notes'] ?? null,
        ]);

        AuditLogService::log(
            $user,
            'asset.created',
            'Asset',
            $asset->id,
            [
                'asset_code' => $asset->asset_code,
                'serial_number' => $asset->serial_number,
                'model_id' => $asset->model_id,
            ],
            null,
            $request->ip(),
            $affiliateId
        );

        return response()->json([
            'success' => true,
            'message' => 'Perangkat elektronik berhasil didaftarkan ke inventaris.',
            'data' => $asset->load(['model.category', 'affiliate']),
        ], 201);
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
        }

        // Format uppercase identifiers
        if (isset($validated['asset_code'])) {
            $validated['asset_code'] = strtoupper(trim($validated['asset_code']));
        }
        if (isset($validated['serial_number'])) {
            $validated['serial_number'] = strtoupper(trim($validated['serial_number']));
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
                'notes',
            ]);

            $asset->update(array_filter($validated, fn($v) => $v !== null));

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

        $startAt = \Carbon\Carbon::parse($request->start_at);
        $endAt = \Carbon\Carbon::parse($request->end_at);
        $pricingService = app(\App\Services\PricingService::class);

        $results = [];
        $allAvailable = true;

        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = $request->get('branch_id') 
            ?? $request->get('affiliate_id') 
            ?? $request->header('X-Branch-Id') 
            ?? $tenantContext->getBranchId();

        if (!$branchId && $businessId) {
            $defaultBranch = \App\Models\Affiliate::where('business_id', $businessId)
                ->where('is_active', true)
                ->orderBy('is_central', 'desc')
                ->first();
            $branchId = $defaultBranch?->id;
        }

        $assets = Asset::with('model')->whereIn('id', $request->asset_ids)->get();
        foreach ($assets as $asset) {
            $isAvailable = $pricingService->isAssetAvailable($asset, $startAt, $endAt);
            if ($branchId && (int)$asset->affiliate_id !== (int)$branchId) {
                $isAvailable = false;
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
}
