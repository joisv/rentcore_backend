<?php

namespace App\Services;

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
     * @throws ValidationException if no open shift exists
     */
    public static function requireActiveShift(
        int $branchId,
        ?User $user = null,
        bool $isCash = true,
        string $actionDescription = ''
    ): CashDrawerShift {
        $tenantContext = app(TenantContext::class);
        $businessId = $tenantContext->getBusinessId() ?? $user?->current_business_id ?? $user?->business_id;

        $query = CashDrawerShift::where('branch_id', $branchId)
            ->where('status', 'open');

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        $shift = $query->latest('opened_at')->first();

        if (!$shift) {
            $action = !empty($actionDescription)
                ? $actionDescription
                : ($isCash ? 'menerima atau mengeluarkan uang tunai' : 'memproses transaksi kasir POS');

            throw ValidationException::withMessages([
                'cash_shift' => [
                    "Shift kasir belum dibuka untuk cabang ini. Buka shift kasir terlebih dahulu sebelum {$action}."
                ],
                'shift' => [
                    "Shift kasir belum dibuka untuk cabang ini. Buka shift kasir terlebih dahulu sebelum {$action}."
                ],
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
