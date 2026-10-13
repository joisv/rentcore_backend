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
        // 1. bookings
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 30)->unique();
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->integer('duration_hours');

            $table->enum('status', ['reserved', 'rented', 'returned', 'cancelled'])->default('rented');

            $table->decimal('subtotal_amount', 12, 2)->default(0.00);
            $table->decimal('deposit_fee', 12, 2)->default(0.00);

            $table->enum('discount_type', ['none', 'voucher', 'manual'])->default('none');
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->string('manual_discount_reason')->nullable();
            $table->uuid('discount_approved_by')->nullable();
            $table->foreign('discount_approved_by')->references('id')->on('users')->nullOnDelete();

            $table->decimal('total_bill', 12, 2);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('paid');

            $table->string('collateral_type', 50)->default('KTP Asli');
            $table->boolean('collateral_secured')->default(true);
            $table->string('collateral_storage_slot', 100)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status'], 'idx_bookings_affiliate_status');
            $table->index(['start_at', 'end_at'], 'idx_bookings_time_range');
            $table->index('created_at', 'idx_bookings_created');
        });

        // 2. booking_items
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->unique(['booking_id', 'asset_id'], 'uq_booking_asset');
            $table->index('asset_id', 'idx_items_asset');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
    }
};
