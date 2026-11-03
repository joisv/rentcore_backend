<?php

namespace App\Http\Requests;

use App\Models\Affiliate;
use App\Models\Business;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class PaymentSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isPlatformAdmin()) {
            return true;
        }

        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId();
        if ($businessId) {
            $membership = $user->getBusinessMembership($businessId);
            if ($membership && in_array(strtolower($membership->role), ['owner', 'admin'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $toBool = function ($val) {
            if ($val === null) {
                return null;
            }
            if (is_bool($val)) {
                return $val;
            }
            if (is_numeric($val)) {
                return (int)$val === 1;
            }
            $lower = strtolower(trim((string)$val));
            if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
            return $val;
        };

        $mergeData = [];

        $rawEwallet = $this->input('ewallet_enabled')
            ?? $this->input('wallet_enable')
            ?? $this->input('wallet_enabled')
            ?? $this->input('e_wallet_enabled')
            ?? $this->input('is_wallet_enabled');

        if ($rawEwallet !== null) {
            $parsed = $toBool($rawEwallet);
            $mergeData['ewallet_enabled'] = $parsed;
            $mergeData['wallet_enable'] = $parsed;
        }

        foreach (['qris_statis_enabled', 'is_dynamic_qris_enabled', 'cash_enabled', 'bank_transfer_enabled', 'remove_qris_image'] as $boolKey) {
            if ($this->has($boolKey)) {
                $mergeData[$boolKey] = $toBool($this->input($boolKey));
            }
        }

        if ($this->has('ewallet_providers') && is_array($this->input('ewallet_providers'))) {
            $providers = $this->input('ewallet_providers');
            foreach ($providers as $provKey => $provVal) {
                $providers[$provKey] = $toBool($provVal);
            }
            $mergeData['ewallet_providers'] = $providers;
        }

        if (!empty($mergeData)) {
            $this->merge($mergeData);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'branch_id' => 'sometimes|nullable|integer|exists:affiliates,id',
            'ewallet_enabled' => 'sometimes|nullable|boolean',
            'wallet_enable' => 'sometimes|nullable|boolean',
            'wallet_enabled' => 'sometimes|nullable|boolean',
            'e_wallet_enabled' => 'sometimes|nullable|boolean',
            'ewallet_providers' => 'sometimes|nullable|array',
            'ewallet_providers.shopeepay' => 'sometimes|nullable|boolean',
            'ewallet_providers.dana' => 'sometimes|nullable|boolean',
            'ewallet_providers.linkaja' => 'sometimes|nullable|boolean',
            'ewallet_providers.ovo' => 'sometimes|nullable|boolean',
            'qris_statis_enabled' => 'sometimes|nullable|boolean',
            'is_dynamic_qris_enabled' => 'sometimes|nullable|boolean',
            'cash_enabled' => 'sometimes|nullable|boolean',
            'bank_transfer_enabled' => 'sometimes|nullable|boolean',
            'remove_qris_image' => 'sometimes|nullable|boolean',
            'qris_image' => 'sometimes|nullable|file|mimes:jpeg,png,jpg|max:2048',
            'qris_image_base64' => 'sometimes|nullable|string',
            'qris_raw_payload' => 'sometimes|nullable|string',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Guard: is_dynamic_qris_enabled cannot be true unless qris_raw_payload is present
            if ($this->boolean('is_dynamic_qris_enabled')) {
                $branchId = $this->input('branch_id');
                $tenantContext = app(TenantContext::class);
                $businessId = $tenantContext->getBusinessId();
                $business = $businessId ? Business::find($businessId) : null;

                $currentRawPayload = null;
                if ($branchId) {
                    $branch = Affiliate::find($branchId);
                    $currentRawPayload = $branch?->qris_raw_payload
                        ?? $branch?->payment_methods_config_override['qris_raw_payload'] ?? null;
                }
                if (!$currentRawPayload && $business) {
                    $currentRawPayload = $business->qris_raw_payload
                        ?? $business->payment_methods_config['qris_raw_payload'] ?? null;
                }

                $hasIncomingPayload = !empty($this->input('qris_raw_payload')) || $this->hasFile('qris_image') || !empty($this->input('qris_image_base64'));

                if (empty($currentRawPayload) && !$hasIncomingPayload) {
                    $validator->errors()->add(
                        'is_dynamic_qris_enabled',
                        'Unggah gambar QRIS statis terlebih dahulu untuk mengaktifkan QRIS Dinamis.'
                    );
                }
            }
        });
    }
}
