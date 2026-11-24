<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'enable_collateral')) {
                $table->boolean('enable_collateral')
                    ->default(false)
                    ->after('max_addons_per_rental');
            }
            if (Schema::hasColumn('businesses', 'require_collateral')) {
                $table->boolean('require_collateral')->default(false)->change();
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'collateral_type')) {
                $table->string('collateral_type', 100)->nullable()->default(null)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'enable_collateral')) {
                $table->dropColumn('enable_collateral');
            }
        });
    }
};
