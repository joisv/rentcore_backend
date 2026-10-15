<?php

namespace App\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply multi-tenant row isolation to the Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $table = $model->getTable();

        if ($context->hasActiveBusiness()) {
            $builder->where($table . '.business_id', $context->getBusinessId());

            if ($context->isBranchRestricted() && in_array('affiliate_id', $model->getFillable())) {
                if ($model instanceof \App\Models\Voucher) {
                    $builder->where(function ($q) use ($table, $context) {
                        $q->whereNull($table . '.affiliate_id')
                          ->orWhere($table . '.affiliate_id', $context->getBranchId());
                    });
                } else {
                    $builder->where($table . '.affiliate_id', $context->getBranchId());
                }
            }
            return;
        }

        // Platform Admin / Super Admin bypass when explicitly querying outside tenant scope
        $user = \Illuminate\Support\Facades\Auth::user();
        if ($user && $user->isSuperAdmin()) {
            return;
        }

        // Console / CLI commands (migrations, seeders, artisan commands) run without tenant context if not authenticated
        if (app()->runningInConsole() && !$user) {
            return;
        }

        // Backwards compatibility for legacy affiliate scoping
        if ($user && $user->affiliate_id) {
            if ($model instanceof \App\Models\InventoryTransfer) {
                $builder->where(function ($q) use ($user) {
                    $q->where('from_affiliate_id', $user->affiliate_id)
                      ->orWhere('to_affiliate_id', $user->affiliate_id);
                });
                return;
            } elseif (in_array('affiliate_id', $model->getFillable())) {
                if ($model instanceof \App\Models\Voucher) {
                    $builder->where(function ($q) use ($table, $user) {
                        $q->whereNull($table . '.affiliate_id')
                          ->orWhere($table . '.affiliate_id', $user->affiliate_id);
                    });
                } else {
                    $builder->where($table . '.affiliate_id', $user->affiliate_id);
                }
                return;
            } else {
                return;
            }
        }

        // FAIL CLOSED: If no active business context and not superadmin, return 0 rows
        $builder->whereRaw('0 = 1');
    }
}
