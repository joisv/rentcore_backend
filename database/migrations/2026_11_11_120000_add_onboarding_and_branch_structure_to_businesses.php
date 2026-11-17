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
        // 1. Add branch_structure and has_completed_onboarding to businesses
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'branch_structure')) {
                $table->string('branch_structure', 50)->default('single_location')->after('business_categories');
            }
            if (!Schema::hasColumn('businesses', 'has_completed_onboarding')) {
                $table->boolean('has_completed_onboarding')->default(false)->after('branch_structure');
            }
        });

        // 2. Add has_completed_walkthrough_v1 to users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'has_completed_walkthrough_v1')) {
                $table->boolean('has_completed_walkthrough_v1')->default(false)->after('is_platform_admin');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'has_completed_walkthrough_v1')) {
                $table->dropColumn('has_completed_walkthrough_v1');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'has_completed_onboarding')) {
                $table->dropColumn('has_completed_onboarding');
            }
            if (Schema::hasColumn('businesses', 'branch_structure')) {
                $table->dropColumn('branch_structure');
            }
        });
    }
};
