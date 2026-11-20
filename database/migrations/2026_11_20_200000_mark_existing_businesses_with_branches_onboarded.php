<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('businesses') || !Schema::hasTable('affiliates') || !Schema::hasColumn('businesses', 'has_completed_onboarding')) {
            return;
        }

        DB::table('businesses')
            ->where('has_completed_onboarding', false)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('affiliates')
                    ->whereColumn('affiliates.business_id', 'businesses.id');
            })
            ->update([
                'has_completed_onboarding' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data backfill migration — intentionally irreversible.
    }
};
