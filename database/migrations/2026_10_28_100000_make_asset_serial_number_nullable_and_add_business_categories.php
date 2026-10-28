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
        // 1. Add business_categories JSON column to businesses table
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'business_categories')) {
                $table->json('business_categories')->nullable()->after('city');
            }
        });

        // 2. Make assets.serial_number nullable for non-serialized items
        Schema::table('assets', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('serial_number', 100)->nullable(false)->change();
        });

        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'business_categories')) {
                $table->dropColumn('business_categories');
            }
        });
    }
};
