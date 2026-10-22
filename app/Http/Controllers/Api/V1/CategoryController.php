<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * List all asset categories.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AssetCategory::withCount('models');

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('is_addon')) {
            $query->where('is_addon', filter_var($request->is_addon, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all', false)) {
            $categories = $query->orderBy('name')->get();
            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        }

        $perPage = (int) $request->get('per_page', 20);
        $categories = $query->orderBy('name')->paginate($perPage);

        if ($categories->currentPage() > $categories->lastPage() && $categories->lastPage() > 0) {
            $categories = $query->orderBy('name')->paginate($perPage, ['*'], 'page', $categories->lastPage());
        }

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get specific category details.
     */
    public function show(int $id): JsonResponse
    {
        $category = AssetCategory::with(['models.pricingTiers'])->withCount('models')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $category,
        ]);
    }

    /**
     * Enforce Owner or Admin authority to manage categories.
     */
    protected function authorizeManageCategories(Request $request): void
    {
        $user = $request->user();
        if ($user && ($user->isSuperAdmin() || $user->isPlatformAdmin())) {
            return;
        }

        $tenant = app(\App\Tenancy\TenantContext::class);
        $businessId = $tenant->getBusinessId() ?? (int)$request->header('X-Business-Id');
        $member = $tenant->getMember() ?? ($businessId && $user ? $user->getBusinessMembership($businessId) : null);
        $isOwnerOrAdmin = $member ? ($member->isOwner() || $member->isAdmin()) : false;

        if (!$isOwnerOrAdmin) {
            abort(response()->json([
                'success' => false,
                'message' => 'Hanya Owner bisnis atau Admin yang berwenang mengelola kategori produk rental.',
                'code' => 'FORBIDDEN',
            ], 403));
        }
    }

    /**
     * Create a new category.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManageCategories($request);

        $businessId = app(\App\Tenancy\TenantContext::class)->getBusinessId();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                \Illuminate\Validation\Rule::unique('asset_categories', 'name')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                \Illuminate\Validation\Rule::unique('asset_categories', 'slug')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'description' => 'nullable|string',
            'is_addon' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $baseSlug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;
        while (AssetCategory::withoutGlobalScopes()->where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $counter++;
            $slug = "{$baseSlug}-{$counter}";
        }

        $category = AssetCategory::create([
            'business_id' => $businessId,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'is_addon' => $validated['is_addon'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLogService::log(
            $request->user(),
            'category.created',
            'AssetCategory',
            $category->id,
            $category->toArray(),
            null,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Kategori rental berhasil dibuat.',
            'data' => $category,
        ], 201);
    }

    /**
     * Update an existing category.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManageCategories($request);

        $category = AssetCategory::findOrFail($id);

        if ($category->business_id === null && !$request->user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori sistem global tidak dapat diubah.',
                'code' => 'GLOBAL_CATEGORY_IMMUTABLE',
            ], 403);
        }

        $old = $category->toArray();
        $businessId = $category->business_id ?? app(\App\Tenancy\TenantContext::class)->getBusinessId();

        $validated = $request->validate([
            'name' => [
                'nullable',
                'string',
                'max:100',
                \Illuminate\Validation\Rule::unique('asset_categories', 'name')->ignore($category->id)->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                \Illuminate\Validation\Rule::unique('asset_categories', 'slug')->ignore($category->id)->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'description' => 'nullable|string',
            'is_addon' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($validated['name']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update(array_filter($validated, fn($v) => $v !== null));

        AuditLogService::log(
            $request->user(),
            'category.updated',
            'AssetCategory',
            $category->id,
            $category->toArray(),
            $old,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Kategori rental berhasil diperbarui.',
            'data' => $category,
        ]);
    }

    /**
     * Delete an existing category.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeManageCategories($request);

        $category = AssetCategory::findOrFail($id);

        if ($category->business_id === null && !$request->user()->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori sistem global tidak dapat dihapus.',
                'code' => 'GLOBAL_CATEGORY_IMMUTABLE',
            ], 403);
        }

        if ($category->models()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori tidak dapat dihapus karena masih memiliki model produk terdaftar.',
                'code' => 'CATEGORY_HAS_MODELS',
            ], 422);
        }

        $old = $category->toArray();
        $category->delete();

        AuditLogService::log(
            $request->user(),
            'category.deleted',
            'AssetCategory',
            $id,
            null,
            $old,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Kategori rental berhasil dihapus.',
        ]);
    }
}
