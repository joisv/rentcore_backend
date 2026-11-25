<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'late_fee_grace_mode')) {
                $table->string('late_fee_grace_mode', 20)
                    ->default('forfeit')
                    ->after('return_grace_period_minutes');
            }
            if (!Schema::hasColumn('businesses', 'late_fee_default_type')) {
                $table->string('late_fee_default_type', 20)
                    ->default('fixed')
                    ->after('default_hourly_late_fee');
            }
            if (!Schema::hasColumn('businesses', 'late_fee_addon_policy')) {
                $table->string('late_fee_addon_policy', 24)
                    ->default('follow_main_unit')
                    ->after('late_fee_default_type');
            }
            if (!Schema::hasColumn('businesses', 'allow_cashier_late_fee_waiver')) {
                $table->boolean('allow_cashier_late_fee_waiver')
                    ->default(true)
                    ->after('late_fee_addon_policy');
            }
        });

        Schema::table('booking_items', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_items', 'applied_late_fee_grace_mode')) {
                $table->string('applied_late_fee_grace_mode', 20)
                    ->nullable()
                    ->after('applied_grace_period_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            if (Schema::hasColumn('booking_items', 'applied_late_fee_grace_mode')) {
                $table->dropColumn('applied_late_fee_grace_mode');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('businesses', 'late_fee_grace_mode') ? 'late_fee_grace_mode' : null,
                Schema::hasColumn('businesses', 'late_fee_default_type') ? 'late_fee_default_type' : null,
                Schema::hasColumn('businesses', 'late_fee_addon_policy') ? 'late_fee_addon_policy' : null,
                Schema::hasColumn('businesses', 'allow_cashier_late_fee_waiver') ? 'allow_cashier_late_fee_waiver' : null,
            ]);
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
