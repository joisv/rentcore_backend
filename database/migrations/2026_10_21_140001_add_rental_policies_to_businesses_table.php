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
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'id_document_limit_enabled')) {
                $table->boolean('id_document_limit_enabled')->default(true)->after('is_active');
            }
            if (!Schema::hasColumn('businesses', 'max_primary_devices_per_id')) {
                $table->unsignedInteger('max_primary_devices_per_id')->default(1)->after('id_document_limit_enabled');
            }
            if (!Schema::hasColumn('businesses', 'addons_enabled')) {
                $table->boolean('addons_enabled')->default(true)->after('max_primary_devices_per_id');
            }
            if (!Schema::hasColumn('businesses', 'max_addons_per_rental')) {
                $table->unsignedInteger('max_addons_per_rental')->nullable()->default(5)->after('addons_enabled');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'id_document_limit_enabled',
                'max_primary_devices_per_id',
                'addons_enabled',
                'max_addons_per_rental',
            ]);
        });
    }
};
