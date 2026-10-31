<?php

namespace App\Services;

use App\Models\Business;
use App\Models\CashDrawerShift;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class ShiftEnforcementService
{
    /**
     * Require that an active, open cash drawer shift exists for the given branch.
     * Enforces strict backend validation, preventing orphaned payments or cash movements.
     *
     * @throws ValidationException if no open shift exists and enforcement is enabled
     */
    public static function requireActiveShift(
        int $branchId,
        ?User $user = null,
        bool $isCash = true,
        string $actionDescription = 'memproses transaksi'
    ): ?CashDrawerShift {
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? $user?->current_business_id ?? $user?->business_id;

        $business = $businessId ? Business::find($businessId) : null;
        if ($business && $business->shift_enforcement_enabled === false) {
            return self::getActiveShift($branchId, $user);
        }

        $query = CashDrawerShift::where('branch_id', $branchId)
            ->where('status', 'open');

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        $shift = $query->latest('opened_at')->first();

        if (!$shift) {
            $action = !empty($actionDescription)
                ? $actionDescription
                : 'memproses transaksi';

            $errorMessage = "Shift belum dibuka. Buka shift kasir terlebih dahulu sebelum {$action}.";

            throw ValidationException::withMessages([
                'shift' => [$errorMessage],
                'cash_shift' => [$errorMessage],
            ])->status(422);
        }

        return $shift;
    }

    /**
     * Safely resolve active shift instance without throwing an exception.
     */
    public static function getActiveShift(int $branchId, ?User $user = null): ?CashDrawerShift
    {
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? $user?->current_business_id ?? $user?->business_id;

        $query = CashDrawerShift::where('branch_id', $branchId)
            ->where('status', 'open');

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        return $query->latest('opened_at')->first();
    }
}
