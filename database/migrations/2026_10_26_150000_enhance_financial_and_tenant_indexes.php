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
        // 1. payment_transactions: convert global unique constraints to compound tenant-scoped unique constraints
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique('payment_transactions_transaction_code_unique');
            $table->dropUnique('payment_transactions_receipt_number_unique');

            $table->unique(['business_id', 'transaction_code'], 'uq_payments_biz_tx_code');
            $table->unique(['business_id', 'receipt_number'], 'uq_payments_biz_receipt');

            // Composite performance indexes
            $table->index(['business_id', 'branch_id', 'transacted_at'], 'idx_payments_biz_branch_transacted');
            $table->index(['business_id', 'status', 'transacted_at'], 'idx_payments_biz_status_transacted');
        });

        // 2. bookings: add composite query indexes
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['business_id', 'affiliate_id', 'status', 'created_at'], 'idx_bookings_biz_branch_status_created');
            $table->index(['business_id', 'status', 'end_at'], 'idx_bookings_biz_status_end');
        });

        // 3. assets: add composite query indexes
        Schema::table('assets', function (Blueprint $table) {
            $table->index(['business_id', 'affiliate_id', 'status'], 'idx_assets_biz_branch_status');
            $table->index(['business_id', 'model_id', 'status'], 'idx_assets_biz_model_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex('idx_assets_biz_model_status');
            $table->dropIndex('idx_assets_biz_branch_status');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_biz_status_end');
            $table->dropIndex('idx_bookings_biz_branch_status_created');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_payments_biz_status_transacted');
            $table->dropIndex('idx_payments_biz_branch_transacted');
            $table->dropUnique('uq_payments_biz_receipt');
            $table->dropUnique('uq_payments_biz_tx_code');

            $table->unique('transaction_code', 'payment_transactions_transaction_code_unique');
            $table->unique('receipt_number', 'payment_transactions_receipt_number_unique');
        });
    }
};
