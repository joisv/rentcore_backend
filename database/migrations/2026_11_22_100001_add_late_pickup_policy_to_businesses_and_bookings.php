<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'booking_grace_period_minutes')) {
                $table->unsignedSmallInteger('booking_grace_period_minutes')
                    ->default(60)
                    ->after('shift_grace_period_minutes');
            }
            if (!Schema::hasColumn('businesses', 'auto_expire_unpaid_booking')) {
                $table->boolean('auto_expire_unpaid_booking')
                    ->default(true)
                    ->after('booking_grace_period_minutes');
            }
            if (!Schema::hasColumn('businesses', 'auto_expire_paid_booking')) {
                $table->boolean('auto_expire_paid_booking')
                    ->default(false)
                    ->after('auto_expire_unpaid_booking');
            }
            if (!Schema::hasColumn('businesses', 'late_pickup_end_time_mode')) {
                $table->string('late_pickup_end_time_mode', 32)
                    ->default('keep_original_end')
                    ->after('auto_expire_paid_booking');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'grace_extended_until')) {
                $table->timestamp('grace_extended_until')
                    ->nullable()
                    ->after('grace_expires_at');
            }
            if (!Schema::hasColumn('bookings', 'is_late_pickup')) {
                $table->boolean('is_late_pickup')
                    ->default(false)
                    ->after('is_overdue_pickup');
            }
            if (!Schema::hasColumn('bookings', 'dp_disposition')) {
                $table->string('dp_disposition', 20)
                    ->nullable()
                    ->after('forfeited_dp_amount');
            }
            if (!Schema::hasColumn('bookings', 'no_show_reason')) {
                $table->string('no_show_reason', 255)
                    ->nullable()
                    ->after('dp_disposition');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('bookings', 'grace_extended_until') ? 'grace_extended_until' : null,
                Schema::hasColumn('bookings', 'is_late_pickup') ? 'is_late_pickup' : null,
                Schema::hasColumn('bookings', 'dp_disposition') ? 'dp_disposition' : null,
                Schema::hasColumn('bookings', 'no_show_reason') ? 'no_show_reason' : null,
            ]);
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('businesses', 'booking_grace_period_minutes') ? 'booking_grace_period_minutes' : null,
                Schema::hasColumn('businesses', 'auto_expire_unpaid_booking') ? 'auto_expire_unpaid_booking' : null,
                Schema::hasColumn('businesses', 'auto_expire_paid_booking') ? 'auto_expire_paid_booking' : null,
                Schema::hasColumn('businesses', 'late_pickup_end_time_mode') ? 'late_pickup_end_time_mode' : null,
            ]);
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
