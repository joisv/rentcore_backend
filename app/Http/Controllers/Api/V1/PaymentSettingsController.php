<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Services\AuditLogService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

        $qrisImageUrl = null;
        if (!empty($config['qris_image_path'])) {
            $version = Storage::disk('local')->exists($config['qris_image_path'])
                ? Storage::disk('local')->lastModified($config['qris_image_path'])
                : time();
            $qrisImageUrl = url("/api/v1/payment-settings/qris-image?business_id={$business->id}" . ($branchId ? "&branch_id={$branchId}" : "") . "&v={$version}");
        }

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
    public function updateSettings(Request $request): JsonResponse
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

        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:affiliates,id',
            'ewallet_enabled' => 'nullable|boolean',
            'ewallet_providers' => 'nullable|array',
            'ewallet_providers.shopeepay' => 'nullable|boolean',
            'ewallet_providers.dana' => 'nullable|boolean',
            'ewallet_providers.linkaja' => 'nullable|boolean',
            'ewallet_providers.ovo' => 'nullable|boolean',
            'qris_statis_enabled' => 'nullable|boolean',
            'cash_enabled' => 'nullable|boolean',
            'bank_transfer_enabled' => 'nullable|boolean',
            'remove_qris_image' => 'nullable|boolean',
            'qris_image' => 'nullable|file|mimes:jpeg,png,webp,jpg|max:2048',
            'qris_image_base64' => 'nullable|string',
        ]);

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
                'qris_image_path' => null,
                'cash_enabled' => true,
                'bank_transfer_enabled' => true,
            ]);

        $newConfig = $currentConfig;

        if (isset($validated['ewallet_enabled'])) {
            $newConfig['ewallet_enabled'] = (bool)$validated['ewallet_enabled'];
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
            if (!empty($newConfig['qris_image_path']) && Storage::disk('local')->exists($newConfig['qris_image_path'])) {
                Storage::disk('local')->delete($newConfig['qris_image_path']);
            }
            $newConfig['qris_image_path'] = null;
        }

        // Handle QR image file upload
        if ($request->hasFile('qris_image')) {
            $file = $request->file('qris_image');
            $ext = strtolower($file->getClientOriginalExtension());
            $filename = sprintf('qris_biz_%d_%d_%s.%s', $business->id, time(), Str::random(8), $ext);
            $newPath = $file->storeAs('qris', $filename, 'local');

            // Delete old file safely
            if (!empty($newConfig['qris_image_path']) && $newConfig['qris_image_path'] !== $newPath && Storage::disk('local')->exists($newConfig['qris_image_path'])) {
                Storage::disk('local')->delete($newConfig['qris_image_path']);
            }

            $newConfig['qris_image_path'] = $newPath;
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
                $filename = sprintf('qris_biz_%d_%d_%s.%s', $business->id, time(), Str::random(8), $ext);
                $newPath = 'qris/' . $filename;
                Storage::disk('local')->put($newPath, $decoded);

                if (!empty($newConfig['qris_image_path']) && $newConfig['qris_image_path'] !== $newPath && Storage::disk('local')->exists($newConfig['qris_image_path'])) {
                    Storage::disk('local')->delete($newConfig['qris_image_path']);
                }

                $newConfig['qris_image_path'] = $newPath;
            }
        }

        // Persist
        if ($branch) {
            $branch->update(['payment_methods_config_override' => $newConfig]);
        } else {
            $business->update(['payment_methods_config' => $newConfig]);
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
                'has_qris_image' => !empty($newConfig['qris_image_path']),
            ]
        );

        return $this->getSettings($request);
    }

    /**
     * Stream static QRIS image securely.
     */
    public function getQrisImage(Request $request): Response
    {
        $businessId = $request->query('business_id');
        $branchId = $request->query('branch_id');

        if (!$businessId && !$branchId) {
            return response()->json(['message' => 'Parameter tidak lengkap.'], 400);
        }

        $business = Business::find($businessId);
        if (!$business && $branchId) {
            $branch = Affiliate::find($branchId);
            $business = $branch?->business;
        }

        if (!$business) {
            return response()->json(['message' => 'Bisnis tidak ditemukan.'], 404);
        }

        $config = $business->getPaymentMethodsConfig($branchId ? (int)$branchId : null);
        $path = $config['qris_image_path'] ?? null;

        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'Gambar QRIS tidak ditemukan.'], 404);
        }

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
