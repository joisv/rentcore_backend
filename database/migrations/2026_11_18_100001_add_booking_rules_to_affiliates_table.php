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
        Schema::table('affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('affiliates', 'booking_grace_period_minutes')) {
                $table->unsignedSmallInteger('booking_grace_period_minutes')
                    ->default(60)
                    ->after('is_dynamic_qris_enabled');
            }
            if (!Schema::hasColumn('affiliates', 'booking_auto_noshow')) {
                $table->boolean('booking_auto_noshow')
                    ->default(true)
                    ->after('booking_grace_period_minutes');
            }
            if (!Schema::hasColumn('affiliates', 'allow_floating_unit_booking')) {
                $table->boolean('allow_floating_unit_booking')
                    ->default(true)
                    ->after('booking_auto_noshow');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('affiliates', 'booking_grace_period_minutes') ? 'booking_grace_period_minutes' : null,
                Schema::hasColumn('affiliates', 'booking_auto_noshow') ? 'booking_auto_noshow' : null,
                Schema::hasColumn('affiliates', 'allow_floating_unit_booking') ? 'allow_floating_unit_booking' : null,
            ]);

            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
