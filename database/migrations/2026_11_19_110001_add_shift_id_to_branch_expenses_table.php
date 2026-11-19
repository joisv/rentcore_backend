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
        if (Schema::hasTable('branch_expenses') && !Schema::hasColumn('branch_expenses', 'shift_id')) {
            Schema::table('branch_expenses', function (Blueprint $table) {
                $table->foreignId('shift_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained('cash_drawer_shifts')
                    ->nullOnDelete();
                $table->index(['shift_id', 'payment_source', 'status'], 'idx_branch_expenses_shift_source_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('branch_expenses') && Schema::hasColumn('branch_expenses', 'shift_id')) {
            Schema::table('branch_expenses', function (Blueprint $table) {
                $table->dropIndex('idx_branch_expenses_shift_source_status');
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            });
        }
    }
};
