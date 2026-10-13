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

        $categories = $query->orderBy('name')->paginate($request->get('per_page', 20));

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
     * Create a new category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:asset_categories,name',
            'slug' => 'nullable|string|max:100|unique:asset_categories,slug',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $slug = !empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['name']);

        $category = AssetCategory::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
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
        $category = AssetCategory::findOrFail($id);
        $old = $category->toArray();

        $validated = $request->validate([
            'name' => 'nullable|string|max:100|unique:asset_categories,name,' . $category->id,
            'slug' => 'nullable|string|max:100|unique:asset_categories,slug,' . $category->id,
            'description' => 'nullable|string',
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
}
