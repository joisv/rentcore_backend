<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Enhance asset_models for non-phone hardware
        Schema::table('asset_models', function (Blueprint $table) {
            $table->string('storage_capacity', 50)->nullable()->change();
            $table->string('color', 50)->nullable()->change();
            if (!Schema::hasColumn('asset_models', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (!Schema::hasColumn('asset_models', 'has_serial_number')) {
                $table->boolean('has_serial_number')->default(true)->after('standard_accessories');
            }
            if (!Schema::hasColumn('asset_models', 'requires_frp_check')) {
                $table->boolean('requires_frp_check')->default(false)->after('has_serial_number');
            }
            if (!Schema::hasColumn('asset_models', 'specifications')) {
                $table->json('specifications')->nullable()->after('requires_frp_check');
            }
        });

        // Default existing phone/tablet models to require FRP/iCloud check
        DB::table('asset_models')
            ->whereIn('brand', ['Apple', 'Samsung', 'Google', 'Xiaomi', 'Oppo', 'Vivo'])
            ->update(['requires_frp_check' => true]);

        // 2. Enhance pricing_tiers for multi-unit durations (hour, day, week, month)
        Schema::table('pricing_tiers', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_tiers', 'duration_unit')) {
                $table->enum('duration_unit', ['hour', 'day', 'week', 'month'])->default('hour')->after('model_id');
            }
            if (!Schema::hasColumn('pricing_tiers', 'duration_value')) {
                $table->unsignedInteger('duration_value')->default(1)->after('duration_unit');
            }
        });

        // Backfill existing tiers: duration_unit = 'hour', duration_value = duration_hours
        DB::table('pricing_tiers')->update([
            'duration_unit' => 'hour',
            'duration_value' => DB::raw('duration_hours'),
        ]);

        // In MySQL, to drop uq_model_duration, an index covering model_id must exist for the foreign key.
        // We create the new unique index first, then drop the legacy unique index.
        Schema::table('pricing_tiers', function (Blueprint $table) {
            $table->unique(['model_id', 'duration_unit', 'duration_value'], 'uq_model_rate_unit');
            $table->dropUnique('uq_model_duration');
        });

        // 3. Enhance booking_items and bookings for immutable rate snapshots
        Schema::table('booking_items', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_items', 'duration_unit')) {
                $table->enum('duration_unit', ['hour', 'day', 'week', 'month'])->default('hour')->after('unit_price');
            }
            if (!Schema::hasColumn('booking_items', 'duration_value')) {
                $table->unsignedInteger('duration_value')->default(1)->after('duration_unit');
            }
            if (!Schema::hasColumn('booking_items', 'rate_snapshot')) {
                $table->json('rate_snapshot')->nullable()->after('duration_value');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'duration_unit')) {
                $table->enum('duration_unit', ['hour', 'day', 'week', 'month'])->default('hour')->after('duration_hours');
            }
            if (!Schema::hasColumn('bookings', 'duration_value')) {
                $table->unsignedInteger('duration_value')->default(1)->after('duration_unit');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['duration_unit', 'duration_value']);
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn(['duration_unit', 'duration_value', 'rate_snapshot']);
        });

        Schema::table('pricing_tiers', function (Blueprint $table) {
            $table->unique(['model_id', 'duration_hours'], 'uq_model_duration');
            $table->dropUnique('uq_model_rate_unit');
            $table->dropColumn(['duration_unit', 'duration_value']);
        });

        Schema::table('asset_models', function (Blueprint $table) {
            $table->dropColumn(['description', 'has_serial_number', 'requires_frp_check', 'specifications']);
            $table->string('storage_capacity', 50)->nullable(false)->change();
            $table->string('color', 50)->nullable(false)->change();
        });
    }
};
