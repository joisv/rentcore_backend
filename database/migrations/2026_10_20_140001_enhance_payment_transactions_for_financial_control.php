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

        Schema::table('payment_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_transactions', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('business_id')->constrained('affiliates')->restrictOnDelete();
            }
            if (!Schema::hasColumn('payment_transactions', 'status')) {
                $table->enum('status', ['completed', 'void', 'reversed'])->default('completed')->after('type');
            }
            if (!Schema::hasColumn('payment_transactions', 'direction')) {
                $table->enum('direction', ['inflow', 'outflow'])->default('inflow')->after('status');
            }
            if (!Schema::hasColumn('payment_transactions', 'external_reference')) {
                $table->string('external_reference', 100)->nullable()->after('receipt_number');
            }
            if (!Schema::hasColumn('payment_transactions', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->unique()->after('external_reference');
            }
            if (!Schema::hasColumn('payment_transactions', 'reversal_of_id')) {
                $table->foreignId('reversal_of_id')->nullable()->after('processed_by')->constrained('payment_transactions')->nullOnDelete();
            }
            if (!Schema::hasColumn('payment_transactions', 'void_reason')) {
                $table->text('void_reason')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('payment_transactions', 'void_by')) {
                $table->uuid('void_by')->nullable()->after('void_reason');
                $table->foreign('void_by')->references('id')->on('users')->nullOnDelete();
            }
        });

        // Safe backfill of branch_id from bookings
        if (Schema::hasTable('bookings')) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement("
                    UPDATE payment_transactions pt
                    INNER JOIN bookings b ON pt.booking_id = b.id
                    SET pt.branch_id = b.affiliate_id
                    WHERE pt.branch_id IS NULL AND b.affiliate_id IS NOT NULL
                ");
            } else {
                DB::statement("
                    UPDATE payment_transactions
                    SET branch_id = (
                        SELECT affiliate_id FROM bookings WHERE bookings.id = payment_transactions.booking_id
                    )
                    WHERE branch_id IS NULL AND booking_id IS NOT NULL
                ");
            }
        }

        // Set direction = 'outflow' for any existing refund transactions
        DB::table('payment_transactions')
            ->where('type', 'refund')
            ->update(['direction' => 'outflow']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('payment_transactions', 'void_by')) {
                $table->dropForeign(['void_by']);
                $table->dropColumn('void_by');
            }
            if (Schema::hasColumn('payment_transactions', 'void_reason')) {
                $table->dropColumn('void_reason');
            }
            if (Schema::hasColumn('payment_transactions', 'reversal_of_id')) {
                $table->dropForeign(['reversal_of_id']);
                $table->dropColumn('reversal_of_id');
            }
            if (Schema::hasColumn('payment_transactions', 'idempotency_key')) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            }
            if (Schema::hasColumn('payment_transactions', 'external_reference')) {
                $table->dropColumn('external_reference');
            }
            if (Schema::hasColumn('payment_transactions', 'direction')) {
                $table->dropColumn('direction');
            }
            if (Schema::hasColumn('payment_transactions', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('payment_transactions', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            }
        });
    }
};
