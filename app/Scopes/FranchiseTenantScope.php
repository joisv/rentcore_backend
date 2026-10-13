<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class FranchiseTenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     * Enforces strict franchise branch data isolation unless the caller is Super-Admin.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && $user->role !== 'super_admin' && $user->affiliate_id) {
            $builder->where($model->getTable() . '.affiliate_id', $user->affiliate_id);
        }
    }
}
