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
        if (Schema::hasTable('payment_transactions') && !Schema::hasColumn('payment_transactions', 'shift_id')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->foreignId('shift_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained('cash_drawer_shifts')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('payment_transactions') && Schema::hasColumn('payment_transactions', 'shift_id')) {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            });
        }
    }
};
