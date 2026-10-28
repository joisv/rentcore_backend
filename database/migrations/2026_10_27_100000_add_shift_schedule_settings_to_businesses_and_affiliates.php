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
            if (!Schema::hasColumn('businesses', 'shift_enforcement_enabled')) {
                $table->boolean('shift_enforcement_enabled')->default(true)->after('addons_enabled');
            }
            if (!Schema::hasColumn('businesses', 'shift_opening_time')) {
                $table->string('shift_opening_time', 5)->default('08:00')->after('shift_enforcement_enabled');
            }
            if (!Schema::hasColumn('businesses', 'shift_closing_time')) {
                $table->string('shift_closing_time', 5)->default('22:00')->after('shift_opening_time');
            }
            if (!Schema::hasColumn('businesses', 'shift_grace_period_minutes')) {
                $table->unsignedInteger('shift_grace_period_minutes')->default(30)->after('shift_closing_time');
            }
        });

        Schema::table('affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('affiliates', 'shift_opening_time_override')) {
                $table->string('shift_opening_time_override', 5)->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('affiliates', 'shift_closing_time_override')) {
                $table->string('shift_closing_time_override', 5)->nullable()->after('shift_opening_time_override');
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
                'shift_enforcement_enabled',
                'shift_opening_time',
                'shift_closing_time',
                'shift_grace_period_minutes',
            ]);
        });

        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn([
                'shift_opening_time_override',
                'shift_closing_time_override',
            ]);
        });
    }
};
