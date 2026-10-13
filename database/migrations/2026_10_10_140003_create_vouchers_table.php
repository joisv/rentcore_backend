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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->cascadeOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('discount_percentage', 5, 2);
            $table->decimal('max_discount_cap', 12, 2)->nullable();
            $table->decimal('min_transaction_amount', 12, 2)->default(0.00);
            $table->enum('barcode_type', ['qr_code', 'datamatrix', 'code128'])->default('qr_code');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->default(1);
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['code', 'is_active', 'start_at', 'end_at'], 'idx_vouchers_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
