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
        // 1. Business expense policy settings
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'expense_pin_threshold')) {
                $table->decimal('expense_pin_threshold', 12, 2)->default(100000.00)->after('addons_enabled');
            }
            if (!Schema::hasColumn('businesses', 'require_expense_pin')) {
                $table->boolean('require_expense_pin')->default(true)->after('expense_pin_threshold');
            }
        });

        // 2. Branch expense policy threshold override
        Schema::table('affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('affiliates', 'expense_pin_threshold_override')) {
                $table->decimal('expense_pin_threshold_override', 12, 2)->nullable()->after('cashier_discount_limit_nominal');
            }
        });

        // 3. Branch expenses voiding and audit fields
        Schema::table('branch_expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('branch_expenses', 'void_reason')) {
                $table->string('void_reason', 255)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('branch_expenses', 'void_by')) {
                $table->uuid('void_by')->nullable()->after('void_reason');
                $table->foreign('void_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('branch_expenses', 'voided_at')) {
                $table->dateTime('voided_at')->nullable()->after('void_by');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE branch_expenses MODIFY COLUMN status ENUM('draft', 'pending_approval', 'approved', 'rejected', 'disbursed', 'void') DEFAULT 'approved'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('branch_expenses', 'void_by')) {
                $table->dropForeign(['void_by']);
                $table->dropColumn('void_by');
            }
            if (Schema::hasColumn('branch_expenses', 'void_reason')) {
                $table->dropColumn('void_reason');
            }
            if (Schema::hasColumn('branch_expenses', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE branch_expenses MODIFY COLUMN status ENUM('draft', 'pending_approval', 'approved', 'rejected', 'disbursed') DEFAULT 'approved'");
        }

        Schema::table('affiliates', function (Blueprint $table) {
            if (Schema::hasColumn('affiliates', 'expense_pin_threshold_override')) {
                $table->dropColumn('expense_pin_threshold_override');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'require_expense_pin')) {
                $table->dropColumn('require_expense_pin');
            }
            if (Schema::hasColumn('businesses', 'expense_pin_threshold')) {
                $table->dropColumn('expense_pin_threshold');
            }
        });
    }
};
