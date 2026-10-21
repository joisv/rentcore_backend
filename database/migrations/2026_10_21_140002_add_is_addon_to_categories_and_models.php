<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_categories', 'is_addon')) {
                $table->boolean('is_addon')->default(false)->after('description');
            }
        });

        Schema::table('asset_models', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_models', 'is_addon')) {
                $table->boolean('is_addon')->default(false)->after('specifications');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_models', function (Blueprint $table) {
            $table->dropColumn('is_addon');
        });

        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropColumn('is_addon');
        });
    }
};
