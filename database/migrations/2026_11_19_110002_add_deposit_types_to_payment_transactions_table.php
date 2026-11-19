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
        if (!Schema::hasTable('payment_transactions')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payment_transactions MODIFY COLUMN type ENUM('dp', 'settlement', 'extension', 'penalty', 'refund', 'exchange', 'deposit', 'deposit_refund') NOT NULL");
        } else {
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->string('type', 32)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('payment_transactions')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payment_transactions MODIFY COLUMN type ENUM('dp', 'settlement', 'extension', 'penalty', 'refund', 'exchange') NOT NULL");
        }
    }
};
