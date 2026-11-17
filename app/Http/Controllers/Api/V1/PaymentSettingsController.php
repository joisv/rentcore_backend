<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentSettingRequest;
use App\Models\Affiliate;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\AuditLogService;
use App\Services\QrisService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PaymentSettingsController extends Controller
{
    /**
     * Check if user is an authorized Owner or Admin.
     */
    protected function checkIsOwnerOrAdmin(Request $request, Business $business): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isPlatformAdmin()) {
            return true;
        }

        $membership = $user->getBusinessMembership($business->id);
        if ($membership && in_array(strtolower($membership->role), ['owner', 'admin'])) {
            return true;
        }

        return false;
    }

    /**
     * Purge all payment setting & method caches for a business and optional branch.
     */
    public static function purgeCache(?int $businessId, ?int $branchId = null): void
    {
        if ($branchId) {
            Cache::forget("branch_{$branchId}_payment_settings");
            Cache::forget("branch_{$branchId}_payment_methods");
        }
        if ($businessId) {
            Cache::forget("business_{$businessId}_payment_settings");
            Cache::forget("business_{$businessId}_payment_methods");

            $business = Business::find($businessId);
            if ($business) {
                foreach ($business->branches()->pluck('id') as $bId) {
                    Cache::forget("branch_{$bId}_payment_settings");
                    Cache::forget("branch_{$bId}_payment_methods");
                }
            }
        }
        Cache::forget("branch_null_payment_settings");
        Cache::forget("branch_null_payment_methods");
    }

    /**
     * Format payment configuration payload cleanly.
     */
    protected function formatSettingsData(Business $business, ?int $branchId, bool $isOwnerOrAdmin): array
    {
        $config = $business->getPaymentMethodsConfig($branchId);
        $qrisImageUrl = $business->getQrisImageUrl($branchId);

        $isBranchOverride = false;
        if ($branchId) {
            $branch = $business->branches()->find($branchId);
            $isBranchOverride = $branch && !empty($branch->payment_methods_config_override);
        }

        return [
            'business_id' => $business->id,
            'branch_id' => $branchId ? (int)$branchId : null,
            'is_branch_override' => $isBranchOverride,
            'is_owner_or_admin' => $isOwnerOrAdmin,
            'config' => [
                'ewallet_enabled' => (bool)($config['ewallet_enabled'] ?? true),
                'ewallet_providers' => [
                    'shopeepay' => (bool)($config['ewallet_providers']['shopeepay'] ?? true),
                    'dana' => (bool)($config['ewallet_providers']['dana'] ?? true),
                    'gopay' => (bool)($config['ewallet_providers']['gopay'] ?? true),
                    'linkaja' => (bool)($config['ewallet_providers']['linkaja'] ?? true),
                    'ovo' => (bool)($config['ewallet_providers']['ovo'] ?? true),
                ],
                'qris_statis_enabled' => (bool)($config['qris_statis_enabled'] ?? true),
                'is_dynamic_qris_enabled' => (bool)($config['is_dynamic_qris_enabled'] ?? false),
                'qris_raw_payload' => $config['qris_raw_payload'] ?? null,
                'qris_image_path' => $config['qris_image_path'] ?? null,
                'qris_image_url' => $qrisImageUrl,
                'cash_enabled' => (bool)($config['cash_enabled'] ?? true),
                'bank_transfer_enabled' => (bool)($config['bank_transfer_enabled'] ?? true),
            ],
        ];
    }

    /**
     * Get effective payment method configuration for business/branch.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = null;
        if ($request->has('branch_id')) {
            $rawBranch = $request->get('branch_id');
            if ($rawBranch !== null && $rawBranch !== '' && $rawBranch !== 'null' && $rawBranch !== 'all') {
                $branchId = (int)$rawBranch;
            }
        } elseif ($request->header('X-Branch-Id')) {
            $branchId = (int)$request->header('X-Branch-Id');
        } elseif ($user?->affiliate_id) {
            $branchId = (int)$user->affiliate_id;
        }

        $business = Business::find($businessId);
        if (!$business && $branchId) {
            $branch = Affiliate::find($branchId);
            $business = $branch?->business;
        }

        if (!$business) {
            return response()->json([
                'success' => false,
                'message' => 'Bisnis tidak ditemukan.',
            ], 404);
        }

        $isOwnerOrAdmin = $this->checkIsOwnerOrAdmin($request, $business);

        $cacheKey = $branchId ? "branch_{$branchId}_payment_settings" : "business_{$businessId}_payment_settings";
        $data = Cache::remember($cacheKey, 3600, function () use ($business, $branchId, $isOwnerOrAdmin) {
            return $this->formatSettingsData($business, $branchId, $isOwnerOrAdmin);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Update payment method configuration (Owner & Admin only).
     */
    public function updateSettings(PaymentSettingRequest $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $business = Business::findOrFail($businessId);

        if (!$this->checkIsOwnerOrAdmin($request, $business)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya Owner dan Admin yang memiliki otorisasi untuk mengubah konfigurasi metode pembayaran.',
            ], 403);
        }

        $validated = $request->validated();
        $qrisService = app(QrisService::class);

        $branchId = null;
        if (array_key_exists('branch_id', $validated)) {
            $rawBranch = $validated['branch_id'];
            if ($rawBranch !== null && $rawBranch !== '' && $rawBranch !== 'null' && $rawBranch !== 'all') {
                $branchId = (int)$rawBranch;
            }
        } elseif ($request->has('branch_id')) {
            $rawBranch = $request->input('branch_id');
            if ($rawBranch !== null && $rawBranch !== '' && $rawBranch !== 'null' && $rawBranch !== 'all') {
                $branchId = (int)$rawBranch;
            }
        }

        $branch = null;
        if ($branchId) {
            $branch = $business->branches()->findOrFail($branchId);
        }

        // Get existing config to merge
        $currentConfig = $branch && !empty($branch->payment_methods_config_override)
            ? $branch->payment_methods_config_override
            : ($business->payment_methods_config ?? [
                'ewallet_enabled' => true,
                'ewallet_providers' => [
                    'shopeepay' => true,
                    'dana' => true,
                    'gopay' => true,
                    'linkaja' => true,
                    'ovo' => true,
                ],
                'qris_statis_enabled' => true,
                'is_dynamic_qris_enabled' => false,
                'qris_raw_payload' => null,
                'qris_image_path' => null,
                'cash_enabled' => true,
                'bank_transfer_enabled' => true,
            ]);

        $newConfig = $currentConfig;

        // Normalization helper for booleans across JSON and multipart/form-data
        $toBool = function ($val, $default = false): bool {
            if ($val === null) return $default;
            return filter_var($val, FILTER_VALIDATE_BOOLEAN);
        };

        if ($request->hasAny(['ewallet_enabled', 'wallet_enable', 'wallet_enabled', 'e_wallet_enabled', 'is_wallet_enabled'])) {
            $rawVal = $request->input('ewallet_enabled')
                ?? $request->input('wallet_enable')
                ?? $request->input('wallet_enabled')
                ?? $request->input('e_wallet_enabled')
                ?? $request->input('is_wallet_enabled');
            $newConfig['ewallet_enabled'] = $toBool($rawVal, true);
        }

        if ($request->has('ewallet_providers') && is_array($request->input('ewallet_providers'))) {
            $providers = $request->input('ewallet_providers');
            $normalizedProviders = $newConfig['ewallet_providers'] ?? [];
            foreach ($providers as $provKey => $provVal) {
                $normalizedProviders[$provKey] = $toBool($provVal, true);
            }
            $newConfig['ewallet_providers'] = $normalizedProviders;
        }

        if ($request->hasAny(['qris_statis_enabled', 'is_qris_enabled', 'qris_enabled'])) {
            $rawVal = $request->input('qris_statis_enabled')
                ?? $request->input('is_qris_enabled')
                ?? $request->input('qris_enabled');
            $newConfig['qris_statis_enabled'] = $toBool($rawVal, true);
        }

        if ($request->hasAny(['cash_enabled', 'is_cash_enabled'])) {
            $rawVal = $request->input('cash_enabled') ?? $request->input('is_cash_enabled');
            $newConfig['cash_enabled'] = $toBool($rawVal, true);
        }

        if ($request->hasAny(['bank_transfer_enabled', 'is_bank_transfer_enabled', 'transfer_enabled'])) {
            $rawVal = $request->input('bank_transfer_enabled')
                ?? $request->input('is_bank_transfer_enabled')
                ?? $request->input('transfer_enabled');
            $newConfig['bank_transfer_enabled'] = $toBool($rawVal, true);
        }

        // Handle QR image removal
        if (!empty($validated['remove_qris_image']) || $toBool($request->input('remove_qris_image'), false)) {
            if (!empty($newConfig['qris_image_path'])) {
                if (Storage::disk('public')->exists($newConfig['qris_image_path'])) {
                    Storage::disk('public')->delete($newConfig['qris_image_path']);
                }
                if (Storage::disk('local')->exists($newConfig['qris_image_path'])) {
                    Storage::disk('local')->delete($newConfig['qris_image_path']);
                }
            }
            $newConfig['qris_image_path'] = null;
            $newConfig['qris_raw_payload'] = null;
            $newConfig['is_dynamic_qris_enabled'] = false;
        }

        // Scoped folder for store / branch QRIS
        $folder = 'qris';

        // Handle QR image file upload
        if ($request->hasFile('qris_image')) {
            $file = $request->file('qris_image');
            $ext = strtolower($file->getClientOriginalExtension());
            $filename = $branch
                ? sprintf('%d_qris_%d.%s', $branch->id, time(), $ext)
                : sprintf('biz_%d_qris_%d.%s', $business->id, time(), $ext);
            $newPath = $file->storeAs($folder, $filename, 'public');
            $absolutePath = Storage::disk('public')->path($newPath);

            $rawPayload = $qrisService->decodeQrisImage($absolutePath);

            if (!$rawPayload || !$qrisService->isValidQrisPayload($rawPayload)) {
                // If decoding fails or is invalid EMVCo QRIS, delete uploaded file and reject
                Storage::disk('public')->delete($newPath);
                return response()->json([
                    'success' => false,
                    'message' => 'Gambar tidak valid atau barcode QRIS tidak terdeteksi.',
                    'errors' => [
                        'qris_image' => ['Gambar tidak valid atau barcode QRIS tidak terdeteksi.'],
                    ],
                ], 422);
            }

            // Delete old file safely
            if (!empty($newConfig['qris_image_path']) && $newConfig['qris_image_path'] !== $newPath) {
                if (Storage::disk('public')->exists($newConfig['qris_image_path'])) {
                    Storage::disk('public')->delete($newConfig['qris_image_path']);
                }
                if (Storage::disk('local')->exists($newConfig['qris_image_path'])) {
                    Storage::disk('local')->delete($newConfig['qris_image_path']);
                }
            }

            $newConfig['qris_image_path'] = $newPath;
            $newConfig['qris_raw_payload'] = $rawPayload;
        } elseif (!empty($validated['qris_image_base64'])) {
            // Handle base64 upload
            $photoData = $validated['qris_image_base64'];
            $ext = 'png';
            if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $matches)) {
                $photoData = substr($photoData, strpos($photoData, ',') + 1);
                $ext = strtolower($matches[1]);
            }
            $decoded = base64_decode($photoData);
            if ($decoded !== false) {
                $filename = $branch
                    ? sprintf('%d_qris_%d.%s', $branch->id, time(), $ext)
                    : sprintf('biz_%d_qris_%d.%s', $business->id, time(), $ext);
                $newPath = $folder . '/' . $filename;
                Storage::disk('public')->put($newPath, $decoded);
                $absolutePath = Storage::disk('public')->path($newPath);

                $rawPayload = $qrisService->decodeQrisImage($absolutePath);

                if (!$rawPayload || !$qrisService->isValidQrisPayload($rawPayload)) {
                    // If decoding fails or is invalid EMVCo QRIS, delete uploaded file and reject
                    Storage::disk('public')->delete($newPath);
                    return response()->json([
                        'success' => false,
                        'message' => 'Gambar tidak valid atau barcode QRIS tidak terdeteksi.',
                        'errors' => [
                            'qris_image' => ['Gambar tidak valid atau barcode QRIS tidak terdeteksi.'],
                        ],
                    ], 422);
                }

                if (!empty($newConfig['qris_image_path']) && $newConfig['qris_image_path'] !== $newPath) {
                    if (Storage::disk('public')->exists($newConfig['qris_image_path'])) {
                        Storage::disk('public')->delete($newConfig['qris_image_path']);
                    }
                    if (Storage::disk('local')->exists($newConfig['qris_image_path'])) {
                        Storage::disk('local')->delete($newConfig['qris_image_path']);
                    }
                }

                $newConfig['qris_image_path'] = $newPath;
                $newConfig['qris_raw_payload'] = $rawPayload;
            }
        } elseif (!empty($validated['qris_raw_payload'])) {
            if ($qrisService->isValidQrisPayload($validated['qris_raw_payload'])) {
                $newConfig['qris_raw_payload'] = $validated['qris_raw_payload'];
            }
        }

        // Handle is_dynamic_qris_enabled toggle guard
        if (isset($validated['is_dynamic_qris_enabled'])) {
            $isDyn = (bool)$validated['is_dynamic_qris_enabled'];
            if ($isDyn && empty($newConfig['qris_raw_payload'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unggah gambar QRIS statis terlebih dahulu untuk mengaktifkan QRIS Dinamis.',
                    'errors' => [
                        'is_dynamic_qris_enabled' => ['Unggah gambar QRIS statis terlebih dahulu untuk mengaktifkan QRIS Dinamis.'],
                    ],
                ], 422);
            }
            $newConfig['is_dynamic_qris_enabled'] = $isDyn;
        }

        // Persist to database
        if ($branch) {
            $branch->update([
                'payment_methods_config_override' => $newConfig,
                'qris_raw_payload' => $newConfig['qris_raw_payload'] ?? null,
                'is_dynamic_qris_enabled' => $newConfig['is_dynamic_qris_enabled'] ?? false,
            ]);
        } else {
            $business->update([
                'payment_methods_config' => $newConfig,
                'qris_raw_payload' => $newConfig['qris_raw_payload'] ?? null,
                'is_dynamic_qris_enabled' => $newConfig['is_dynamic_qris_enabled'] ?? false,
            ]);
        }

        // Cache Eviction: Purge cached records strictly before returning
        self::purgeCache($business->id, $branchId);

        AuditLogService::log(
            $request->user(),
            'payment_settings.updated',
            'Business',
            $business->slug,
            [
                'branch_id' => $branchId,
                'ewallet_enabled' => $newConfig['ewallet_enabled'] ?? null,
                'qris_statis_enabled' => $newConfig['qris_statis_enabled'] ?? null,
                'is_dynamic_qris_enabled' => $newConfig['is_dynamic_qris_enabled'] ?? null,
                'has_qris_image' => !empty($newConfig['qris_image_path']),
                'has_qris_payload' => !empty($newConfig['qris_raw_payload']),
            ]
        );

        $freshBusiness = $business->fresh();
        $freshData = $this->formatSettingsData(
            $freshBusiness,
            $branchId,
            $this->checkIsOwnerOrAdmin($request, $freshBusiness)
        );

        return response()->json([
            'success' => true,
            'data' => $freshData,
        ]);
    }

    /**
     * Dedicated endpoint for QRIS image upload.
     */
    public function uploadQrisImage(Request $request): JsonResponse
    {
        $appRequest = app(PaymentSettingRequest::class);
        return $this->updateSettings($appRequest);
    }

    /**
     * Stream static QRIS image securely.
     */
    public function getQrisImage(Request $request): Response
    {
        $businessId = $request->query('business_id');
        $branchId = $request->query('branch_id');

        if (!$businessId && $branchId) {
            $branch = Affiliate::find($branchId);
            $business = $branch?->business;
        } else {
            $business = Business::find($businessId);
        }

        if (!$business) {
            return response()->json(['message' => 'Bisnis tidak ditemukan.'], 404);
        }

        $config = $business->getPaymentMethodsConfig($branchId ? (int)$branchId : null);
        $path = $config['qris_image_path'] ?? null;

        if ($path) {
            if (Storage::disk('public')->exists($path)) {
                return response()->file(Storage::disk('public')->path($path), [
                    'Cache-Control' => 'no-cache, private, must-revalidate',
                    'Content-Disposition' => 'inline',
                ]);
            }

            if (Storage::disk('local')->exists($path)) {
                return response()->file(Storage::disk('local')->path($path), [
                    'Cache-Control' => 'no-cache, private, must-revalidate',
                    'Content-Disposition' => 'inline',
                ]);
            }
        }

        return response()->json(['message' => 'Gambar QRIS tidak ditemukan.'], 404);
    }
}
