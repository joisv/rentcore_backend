<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'return_grace_period_minutes')) {
                $table->unsignedSmallInteger('return_grace_period_minutes')
                    ->default(15)
                    ->after('late_pickup_end_time_mode');
            }
            if (!Schema::hasColumn('businesses', 'late_fee_rounding_mode')) {
                $table->string('late_fee_rounding_mode', 32)
                    ->default('hourly_ceil')
                    ->after('return_grace_period_minutes');
            }
            if (!Schema::hasColumn('businesses', 'cap_late_fee_at_daily_rate')) {
                $table->boolean('cap_late_fee_at_daily_rate')
                    ->default(true)
                    ->after('late_fee_rounding_mode');
            }
            if (!Schema::hasColumn('businesses', 'default_hourly_late_fee')) {
                $table->decimal('default_hourly_late_fee', 12, 2)
                    ->nullable()
                    ->default(0.00)
                    ->after('cap_late_fee_at_daily_rate');
            }
        });

        Schema::table('asset_models', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_models', 'hourly_late_fee')) {
                $table->decimal('hourly_late_fee', 12, 2)
                    ->nullable()
                    ->after('is_active');
            }
        });

        Schema::table('assets', function (Blueprint $table) {
            if (!Schema::hasColumn('assets', 'hourly_late_fee')) {
                $table->decimal('hourly_late_fee', 12, 2)
                    ->nullable()
                    ->after('battery_health');
            }
        });

        Schema::table('booking_items', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_items', 'applied_hourly_late_fee')) {
                $table->decimal('applied_hourly_late_fee', 12, 2)
                    ->nullable()
                    ->after('rate_snapshot');
            }
            if (!Schema::hasColumn('booking_items', 'applied_grace_period_minutes')) {
                $table->unsignedSmallInteger('applied_grace_period_minutes')
                    ->nullable()
                    ->after('applied_hourly_late_fee');
            }
            if (!Schema::hasColumn('booking_items', 'applied_daily_rate_cap')) {
                $table->decimal('applied_daily_rate_cap', 12, 2)
                    ->nullable()
                    ->after('applied_grace_period_minutes');
            }
            if (!Schema::hasColumn('booking_items', 'applied_late_fee_rounding_mode')) {
                $table->string('applied_late_fee_rounding_mode', 32)
                    ->nullable()
                    ->after('applied_daily_rate_cap');
            }
        });

        Schema::table('return_inspections', function (Blueprint $table) {
            if (!Schema::hasColumn('return_inspections', 'calculated_late_fee')) {
                $table->decimal('calculated_late_fee', 12, 2)
                    ->default(0.00)
                    ->after('late_fee');
            }
            if (!Schema::hasColumn('return_inspections', 'waived_late_fee')) {
                $table->decimal('waived_late_fee', 12, 2)
                    ->default(0.00)
                    ->after('calculated_late_fee');
            }
            if (!Schema::hasColumn('return_inspections', 'final_late_fee')) {
                $table->decimal('final_late_fee', 12, 2)
                    ->default(0.00)
                    ->after('waived_late_fee');
            }
            if (!Schema::hasColumn('return_inspections', 'late_fee_waive_reason')) {
                $table->text('late_fee_waive_reason')
                    ->nullable()
                    ->after('final_late_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('return_inspections', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('return_inspections', 'calculated_late_fee') ? 'calculated_late_fee' : null,
                Schema::hasColumn('return_inspections', 'waived_late_fee') ? 'waived_late_fee' : null,
                Schema::hasColumn('return_inspections', 'final_late_fee') ? 'final_late_fee' : null,
                Schema::hasColumn('return_inspections', 'late_fee_waive_reason') ? 'late_fee_waive_reason' : null,
            ]);
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('booking_items', 'applied_hourly_late_fee') ? 'applied_hourly_late_fee' : null,
                Schema::hasColumn('booking_items', 'applied_grace_period_minutes') ? 'applied_grace_period_minutes' : null,
                Schema::hasColumn('booking_items', 'applied_daily_rate_cap') ? 'applied_daily_rate_cap' : null,
                Schema::hasColumn('booking_items', 'applied_late_fee_rounding_mode') ? 'applied_late_fee_rounding_mode' : null,
            ]);
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'hourly_late_fee')) {
                $table->dropColumn('hourly_late_fee');
            }
        });

        Schema::table('asset_models', function (Blueprint $table) {
            if (Schema::hasColumn('asset_models', 'hourly_late_fee')) {
                $table->dropColumn('hourly_late_fee');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('businesses', 'return_grace_period_minutes') ? 'return_grace_period_minutes' : null,
                Schema::hasColumn('businesses', 'late_fee_rounding_mode') ? 'late_fee_rounding_mode' : null,
                Schema::hasColumn('businesses', 'cap_late_fee_at_daily_rate') ? 'cap_late_fee_at_daily_rate' : null,
                Schema::hasColumn('businesses', 'default_hourly_late_fee') ? 'default_hourly_late_fee' : null,
            ]);
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
