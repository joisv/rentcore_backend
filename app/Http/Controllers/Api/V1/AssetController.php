<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    /**
     * List available serialized assets at current branch.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Asset::with(['model.category', 'model.pricingTiers']);

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

        $assets = $query->paginate($request->get('per_page', 20));

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
        $models = AssetModel::with(['category', 'pricingTiers' => function ($q) {
            $q->where('is_active', true);
        }])
        ->where('is_active', true)
        ->get();

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
        $affiliateId = $user->isSuperAdmin() 
            ? ($request->get('affiliate_id') ?? $user->affiliate_id)
            : $user->affiliate_id;

        $validated = $request->validate([
            'model_id' => 'required|exists:asset_models,id',
            'asset_code' => 'required|string|max:50|unique:assets,asset_code',
            'serial_number' => 'required|string|max:100|unique:assets,serial_number',
            'imei_1' => 'nullable|string|max:50',
            'imei_2' => 'nullable|string|max:50',
            'condition_grade' => 'nullable|in:A,B,C',
            'battery_health' => 'nullable|integer|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $asset = Asset::create([
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
     * Update asset condition, notes, or maintenance status.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $oldValues = $asset->only(['condition_grade', 'battery_health', 'status', 'notes']);

        $validated = $request->validate([
            'condition_grade' => 'nullable|in:A,B,C',
            'battery_health' => 'nullable|integer|min:0|max:100',
            'status' => 'nullable|in:ready,maintenance,retired,lost',
            'notes' => 'nullable|string',
        ]);

        $asset->update(array_filter($validated, fn($v) => $v !== null));

        AuditLogService::log(
            $request->user(),
            'asset.updated',
            'Asset',
            $asset->id,
            $asset->only(['condition_grade', 'battery_health', 'status', 'notes']),
            $oldValues,
            $request->ip(),
            $asset->affiliate_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Data perangkat berhasil diperbarui.',
            'data' => $asset->fresh(['model.category', 'affiliate']),
        ]);
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

        $assets = Asset::with('model')->whereIn('id', $request->asset_ids)->get();
        foreach ($assets as $asset) {
            $isAvailable = $pricingService->isAssetAvailable($asset, $startAt, $endAt);
            if (!$isAvailable) {
                $allAvailable = false;
            }
            $results[] = [
                'asset_id' => $asset->id,
                'asset_code' => $asset->asset_code,
                'model_name' => $asset->model->name,
                'status' => $asset->status,
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
