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
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        protected PricingService $pricingService
    ) {}

    /**
     * List all rental products (models) with filters and pricing tiers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AssetModel::with(['category', 'pricingTiers' => function ($q) {
            $q->where('is_active', true)->orderBy('duration_hours');
        }])
        ->withCount(['assets', 'assets as ready_units_count' => function ($q) {
            $q->where('status', 'ready');
        }]);

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
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

        $products = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

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
        $product = AssetModel::with([
            'category',
            'pricingTiers',
            'assets' => function ($q) {
                $q->with('affiliate');
            }
        ])
        ->withCount(['assets', 'assets as ready_units_count' => function ($q) {
            $q->where('status', 'ready');
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
        $validated = $request->validate([
            'category_id' => 'required|exists:asset_categories,id',
            'brand' => 'required|string|max:100',
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150|unique:asset_models,slug',
            'description' => 'nullable|string',
            'storage_capacity' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'standard_accessories' => 'nullable|array',
            'has_serial_number' => 'nullable|boolean',
            'requires_frp_check' => 'nullable|boolean',
            'specifications' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'rates' => 'nullable|array',
            'rates.*.duration_unit' => 'required_with:rates|in:hour,day,week,month',
            'rates.*.duration_value' => 'required_with:rates|integer|min:1',
            'rates.*.price' => 'required_with:rates|numeric|min:0',
            'rates.*.extension_hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $slug = !empty($validated['slug']) 
            ? Str::slug($validated['slug']) 
            : Str::slug($validated['brand'] . ' ' . $validated['name'] . ' ' . ($validated['storage_capacity'] ?? ''));

        // Ensure unique slug fallback
        if (AssetModel::where('slug', $slug)->exists()) {
            $slug .= '-' . Str::random(5);
        }

        $product = AssetModel::create([
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
     * Update product details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = AssetModel::findOrFail($id);
        $old = $product->toArray();

        $validated = $request->validate([
            'category_id' => 'nullable|exists:asset_categories,id',
            'brand' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:150',
            'slug' => 'nullable|string|max:150|unique:asset_models,slug,' . $product->id,
            'description' => 'nullable|string',
            'storage_capacity' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'standard_accessories' => 'nullable|array',
            'has_serial_number' => 'nullable|boolean',
            'requires_frp_check' => 'nullable|boolean',
            'specifications' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $product->update(array_filter($validated, fn($v) => $v !== null));

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
    }

    /**
     * Add or update rate cards for a product.
     */
    public function updateRates(Request $request, int $id): JsonResponse
    {
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
