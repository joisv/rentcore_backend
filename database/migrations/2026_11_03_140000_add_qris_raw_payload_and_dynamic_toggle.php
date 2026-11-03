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
            if (!Schema::hasColumn('businesses', 'qris_raw_payload')) {
                $table->text('qris_raw_payload')->nullable()->after('payment_methods_config');
            }
            if (!Schema::hasColumn('businesses', 'is_dynamic_qris_enabled')) {
                $table->boolean('is_dynamic_qris_enabled')->default(false)->after('qris_raw_payload');
            }
        });

        Schema::table('affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('affiliates', 'qris_raw_payload')) {
                $table->text('qris_raw_payload')->nullable()->after('payment_methods_config_override');
            }
            if (!Schema::hasColumn('affiliates', 'is_dynamic_qris_enabled')) {
                $table->boolean('is_dynamic_qris_enabled')->nullable()->after('qris_raw_payload');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'is_dynamic_qris_enabled')) {
                $table->dropColumn('is_dynamic_qris_enabled');
            }
            if (Schema::hasColumn('businesses', 'qris_raw_payload')) {
                $table->dropColumn('qris_raw_payload');
            }
        });

        Schema::table('affiliates', function (Blueprint $table) {
            if (Schema::hasColumn('affiliates', 'is_dynamic_qris_enabled')) {
                $table->dropColumn('is_dynamic_qris_enabled');
            }
            if (Schema::hasColumn('affiliates', 'qris_raw_payload')) {
                $table->dropColumn('qris_raw_payload');
            }
        });
    }
};
