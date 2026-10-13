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
        // 1. payment_methods
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. payment_transactions
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_code', 40)->unique();
            $table->string('receipt_number', 40)->unique();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->uuid('processed_by');
            $table->foreign('processed_by')->references('id')->on('users')->restrictOnDelete();

            $table->enum('type', ['dp', 'settlement', 'extension', 'penalty', 'refund']);
            $table->decimal('amount', 12, 2);
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('cash_change', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->dateTime('transacted_at');
            $table->timestamps();

            $table->index('booking_id', 'idx_payments_booking');
            $table->index('transacted_at', 'idx_payments_transacted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_methods');
    }
};
