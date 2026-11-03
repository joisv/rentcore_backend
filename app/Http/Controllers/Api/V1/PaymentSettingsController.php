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
     * Get effective payment method configuration for business/branch.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();

        $branchId = $request->get('branch_id')
            ?? $request->header('X-Branch-Id')
            ?? $user?->affiliate_id;

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

        $config = $business->getPaymentMethodsConfig($branchId ? (int)$branchId : null);
        $qrisImageUrl = $business->getQrisImageUrl($branchId ? (int)$branchId : null);

        $isBranchOverride = false;
        if ($branchId) {
            $branch = $business->branches()->find($branchId);
            $isBranchOverride = $branch && !empty($branch->payment_methods_config_override);
        }

        $isOwnerOrAdmin = $this->checkIsOwnerOrAdmin($request, $business);

        return response()->json([
            'success' => true,
            'data' => [
                'business_id' => $business->id,
                'branch_id' => $branchId ? (int)$branchId : null,
                'is_branch_override' => $isBranchOverride,
                'is_owner_or_admin' => $isOwnerOrAdmin,
                'config' => [
                    'ewallet_enabled' => (bool)($config['ewallet_enabled'] ?? true),
                    'ewallet_providers' => [
                        'shopeepay' => (bool)($config['ewallet_providers']['shopeepay'] ?? true),
                        'dana' => (bool)($config['ewallet_providers']['dana'] ?? true),
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
            ],
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

        $branchId = !empty($validated['branch_id']) ? (int)$validated['branch_id'] : null;
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

        $ewalletVal = $validated['ewallet_enabled']
            ?? $validated['wallet_enable']
            ?? $validated['wallet_enabled']
            ?? $validated['e_wallet_enabled']
            ?? null;
        if ($ewalletVal !== null) {
            $newConfig['ewallet_enabled'] = (bool)$ewalletVal;
        }

        if (isset($validated['ewallet_providers']) && is_array($validated['ewallet_providers'])) {
            $newConfig['ewallet_providers'] = array_merge(
                $newConfig['ewallet_providers'] ?? [],
                $validated['ewallet_providers']
            );
        }

        if (isset($validated['qris_statis_enabled'])) {
            $newConfig['qris_statis_enabled'] = (bool)$validated['qris_statis_enabled'];
        }

        if (isset($validated['cash_enabled'])) {
            $newConfig['cash_enabled'] = (bool)$validated['cash_enabled'];
        }

        if (isset($validated['bank_transfer_enabled'])) {
            $newConfig['bank_transfer_enabled'] = (bool)$validated['bank_transfer_enabled'];
        }

        // Handle QR image removal
        if (!empty($validated['remove_qris_image'])) {
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

        return $this->getSettings($request);
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
