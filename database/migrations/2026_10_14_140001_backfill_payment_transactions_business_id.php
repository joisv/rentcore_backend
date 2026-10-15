<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('payment_transactions') || !Schema::hasTable('bookings')) {
            return;
        }

        // Safe idempotent backfill of business_id for orphaned payment transactions
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                UPDATE payment_transactions pt
                INNER JOIN bookings b ON pt.booking_id = b.id
                SET pt.business_id = b.business_id
                WHERE pt.business_id IS NULL AND b.business_id IS NOT NULL
            ");
        } else {
            DB::statement("
                UPDATE payment_transactions
                SET business_id = (
                    SELECT business_id FROM bookings WHERE bookings.id = payment_transactions.booking_id
                )
                WHERE business_id IS NULL AND booking_id IS NOT NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive backfill: no rollback required
    }
};
