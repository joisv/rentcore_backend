<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssetModel;
use App\Models\PricingTier;
use App\Services\AuditLogService;
use App\Services\PricingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        protected PricingService $pricingService
    ) {}

    /**
     * Enforce Owner or Admin authority to manage catalog and rates.
     */
    protected function authorizeManageProducts(Request $request): void
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
                'message' => 'Hanya Owner bisnis atau Admin yang berwenang mengelola katalog produk dan tarif rental.',
                'code' => 'FORBIDDEN',
            ], 403));
        }
    }

    /**
     * List all rental products (models) with filters and pricing tiers.
     */
    public function index(Request $request): JsonResponse
    {
        // Catalog entries (models) are business-level and are never hidden from a branch just
        // because another branch created them. Unit counts, however, are branch-specific and are
        // based on the authoritative current branch (assets.affiliate_id), so transferred units
        // count only at their destination. Pass all_branches=1 for a business-wide view
        // (only honored for members who are not branch-restricted).
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $countBranchId = $tenantContext->getBranchId();
        if ($request->boolean('all_branches') && !$tenantContext->isBranchRestricted()) {
            $countBranchId = null;
        }

        $query = AssetModel::with(['category', 'pricingTiers' => function ($q) {
            $q->where('is_active', true)->orderBy('duration_hours');
        }])
        ->withCount([
            'assets' => function ($q) use ($countBranchId) {
                if ($countBranchId) {
                    $q->where('assets.affiliate_id', $countBranchId);
                }
            },
            'assets as ready_units_count' => function ($q) use ($countBranchId) {
                $q->where('status', 'ready');
                if ($countBranchId) {
                    $q->where('assets.affiliate_id', $countBranchId);
                }
            },
        ]);

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('is_addon')) {
            $isAddonFilter = filter_var($request->is_addon, FILTER_VALIDATE_BOOLEAN);
            if ($isAddonFilter) {
                $query->addons();
            } else {
                $query->devices();
            }
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all', false)) {
            $products = $query->orderBy('name')->get();
            return response()->json([
                'success' => true,
                'data' => $products,
            ]);
        }

        $perPage = (int) $request->get('per_page', 20);
        $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

        if ($products->currentPage() > $products->lastPage() && $products->lastPage() > 0) {
            $products = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $products->lastPage());
        }

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Get specific product details with rates and units.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $branchId = $tenantContext->getBranchId();
        if ($request->boolean('all_branches') && !$tenantContext->isBranchRestricted()) {
            $branchId = null;
        }
        $branchFilter = function ($q) use ($branchId) {
            if ($branchId) {
                $q->where('assets.affiliate_id', $branchId);
            }
        };

        $product = AssetModel::with([
            'category',
            'pricingTiers',
            'assets' => function ($q) use ($branchFilter) {
                $branchFilter($q);
                $q->with('affiliate');
            }
        ])
        ->withCount(['assets' => $branchFilter, 'assets as ready_units_count' => function ($q) use ($branchFilter) {
            $q->where('status', 'ready');
            $branchFilter($q);
        }])
        ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * Create a new product and optional rate cards.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManageProducts($request);

        $businessId = app(\App\Tenancy\TenantContext::class)->getBusinessId();

        $validated = $request->validate([
            'category_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('asset_categories', 'id')->where(function ($q) use ($businessId) {
                    $q->where(function ($sub) use ($businessId) {
                        $sub->whereNull('business_id');
                        if ($businessId) {
                            $sub->orWhere('business_id', $businessId);
                        }
                    });
                }),
            ],
            'brand' => 'required|string|max:100',
            'name' => 'required|string|max:150',
            'slug' => [
                'nullable',
                'string',
                'max:150',
                \Illuminate\Validation\Rule::unique('asset_models', 'slug')->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'description' => 'nullable|string',
            'storage_capacity' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'standard_accessories' => 'nullable|array',
            'has_serial_number' => 'nullable|boolean',
            'requires_frp_check' => 'nullable|boolean',
            'specifications' => 'nullable|array',
            'is_addon' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'rates' => 'nullable|array',
            'rates.*.duration_unit' => 'required_with:rates|in:hour,day,week,month',
            'rates.*.duration_value' => 'required_with:rates|integer|min:1',
            'rates.*.price' => 'required_with:rates|numeric|min:0',
            'rates.*.extension_hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $slug = !empty($validated['slug']) 
            ? Str::slug($validated['slug']) 
            : Str::slug(trim($validated['brand'] . ' ' . $validated['name'] . (!empty($validated['storage_capacity']) ? ' ' . $validated['storage_capacity'] : '')));

        // Ensure unique slug fallback per business
        if (AssetModel::where('business_id', $businessId)->where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(5);
        }

        $product = AssetModel::create([
            'business_id' => $businessId,
            'category_id' => $validated['category_id'],
            'brand' => $validated['brand'],
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'storage_capacity' => $validated['storage_capacity'] ?? null,
            'color' => $validated['color'] ?? null,
            'standard_accessories' => $validated['standard_accessories'] ?? null,
            'has_serial_number' => $validated['has_serial_number'] ?? true,
            'requires_frp_check' => $validated['requires_frp_check'] ?? false,
            'specifications' => $validated['specifications'] ?? null,
            'is_addon' => $validated['is_addon'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Process initial rates if provided
        if (!empty($validated['rates'])) {
            foreach ($validated['rates'] as $rate) {
                $unit = strtolower($rate['duration_unit']);
                $val = (int)$rate['duration_value'];
                $hours = $this->pricingService->computeDurationHours($unit, $val);

                PricingTier::updateOrCreate(
                    [
                        'model_id' => $product->id,
                        'duration_unit' => $unit,
                        'duration_value' => $val,
                    ],
                    [
                        'business_id' => $businessId,
                        'duration_hours' => $hours,
                        'price' => $rate['price'],
                        'extension_hourly_rate' => $rate['extension_hourly_rate'] ?? 15000.00,
                        'is_active' => true,
                    ]
                );
            }
        }

        AuditLogService::log(
            $request->user(),
            'product.created',
            'AssetModel',
            $product->id,
            $product->toArray(),
            null,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Produk rental baru berhasil ditambahkan.',
            'data' => $product->load(['category', 'pricingTiers']),
        ], 201);
    }

    /**
     * Update product details and optionally synchronize pricing tiers.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeManageProducts($request);

        $product = AssetModel::findOrFail($id);
        $old = $product->toArray();

        $businessId = $product->business_id ?? app(\App\Tenancy\TenantContext::class)->getBusinessId();

        $validated = $request->validate([
            'category_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('asset_categories', 'id')->where(function ($q) use ($businessId) {
                    $q->where(function ($sub) use ($businessId) {
                        $sub->whereNull('business_id');
                        if ($businessId) {
                            $sub->orWhere('business_id', $businessId);
                        }
                    });
                }),
            ],
            'brand' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:150',
            'slug' => [
                'nullable',
                'string',
                'max:150',
                \Illuminate\Validation\Rule::unique('asset_models', 'slug')->ignore($product->id)->where(function ($q) use ($businessId) {
                    if ($businessId) {
                        $q->where('business_id', $businessId);
                    }
                }),
            ],
            'description' => 'nullable|string',
            'storage_capacity' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'standard_accessories' => 'nullable|array',
            'has_serial_number' => 'nullable|boolean',
            'requires_frp_check' => 'nullable|boolean',
            'specifications' => 'nullable|array',
            'is_addon' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'rates' => 'nullable|array',
            'rates.*.duration_unit' => 'required_with:rates|in:hour,day,week,month',
            'rates.*.duration_value' => 'required_with:rates|integer|min:1',
            'rates.*.price' => 'required_with:rates|numeric|min:0',
            'rates.*.extension_hourly_rate' => 'nullable|numeric|min:0',
            'rates.*.is_active' => 'nullable|boolean',
        ]);

        return DB::transaction(function () use ($request, $product, $old, $validated, $businessId) {
            $productFields = collect($validated)->except(['rates'])->filter(fn($v) => $v !== null)->toArray();
            if (!empty($productFields)) {
                $product->update($productFields);
            }

            if ($request->has('rates') && is_array($request->rates)) {
                $submittedKeys = [];
                foreach ($request->rates as $rate) {
                    $unit = strtolower($rate['duration_unit']);
                    $val = (int)$rate['duration_value'];
                    $hours = $this->pricingService->computeDurationHours($unit, $val);
                    $submittedKeys[] = $unit . '_' . $val;

                    PricingTier::updateOrCreate(
                        [
                            'model_id' => $product->id,
                            'duration_unit' => $unit,
                            'duration_value' => $val,
                        ],
                        [
                            'business_id' => $businessId,
                            'duration_hours' => $hours,
                            'price' => $rate['price'],
                            'extension_hourly_rate' => $rate['extension_hourly_rate'] ?? 15000.00,
                            'is_active' => $rate['is_active'] ?? true,
                        ]
                    );
                }

                if (!empty($submittedKeys)) {
                    PricingTier::where('model_id', $product->id)
                        ->whereNotIn(DB::raw("CONCAT(duration_unit, '_', duration_value)"), $submittedKeys)
                        ->update(['is_active' => false]);
                }
            }

            AuditLogService::log(
                $request->user(),
                'product.updated',
                'AssetModel',
                $product->id,
                $product->toArray(),
                $old,
                $request->ip()
            );

            return response()->json([
                'success' => true,
                'message' => 'Data produk rental berhasil diperbarui.',
                'data' => $product->fresh(['category', 'pricingTiers']),
            ]);
        });
    }

    /**
     * Add or update rate cards for a product.
     */
    public function updateRates(Request $request, int $id): JsonResponse
    {
        $this->authorizeManageProducts($request);

        $product = AssetModel::findOrFail($id);

        $request->validate([
            'rates' => 'required|array|min:1',
            'rates.*.duration_unit' => 'required|in:hour,day,week,month',
            'rates.*.duration_value' => 'required|integer|min:1',
            'rates.*.price' => 'required|numeric|min:0',
            'rates.*.extension_hourly_rate' => 'nullable|numeric|min:0',
            'rates.*.is_active' => 'nullable|boolean',
        ]);

        $createdOrUpdated = [];
        foreach ($request->rates as $rate) {
            $unit = strtolower($rate['duration_unit']);
            $val = (int)$rate['duration_value'];
            $hours = $this->pricingService->computeDurationHours($unit, $val);

            $tier = PricingTier::updateOrCreate(
                [
                    'model_id' => $product->id,
                    'duration_unit' => $unit,
                    'duration_value' => $val,
                ],
                [
                    'duration_hours' => $hours,
                    'price' => $rate['price'],
                    'extension_hourly_rate' => $rate['extension_hourly_rate'] ?? 15000.00,
                    'is_active' => $rate['is_active'] ?? true,
                ]
            );
            $createdOrUpdated[] = $tier;
        }

        return response()->json([
            'success' => true,
            'message' => 'Daftar tarif rental produk berhasil diperbarui.',
            'data' => $product->fresh(['pricingTiers']),
        ]);
    }

    /**
     * Centralized dynamic pricing calculation endpoint.
     */
    public function calculatePrice(Request $request): JsonResponse
    {
        $request->validate([
            'model_id' => 'required|exists:asset_models,id',
            'duration_unit' => 'required|in:hour,day,week,month',
            'duration_value' => 'required|integer|min:1',
            'start_at' => 'nullable|date',
        ]);

        $model = AssetModel::with('category')->findOrFail($request->model_id);
        $startAt = $request->filled('start_at') ? Carbon::parse($request->start_at) : Carbon::now();

        $result = $this->pricingService->calculate(
            $model,
            $request->duration_unit,
            (int)$request->duration_value,
            $startAt
        );

        return response()->json([
            'success' => true,
            'data' => [
                'price' => $result['price'],
                'extension_hourly_rate' => $result['extension_hourly_rate'],
                'duration_unit' => $result['duration_unit'],
                'duration_value' => $result['duration_value'],
                'duration_hours' => $result['duration_hours'],
                'start_at' => $result['start_at']->toIso8601String(),
                'end_at' => $result['end_at']->toIso8601String(),
                'rate_snapshot' => $result['rate_snapshot'],
            ],
        ]);
    }
}
