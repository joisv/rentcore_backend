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
        if (Schema::hasTable('branch_expenses')) {
            return;
        }

        Schema::create('branch_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('affiliates')->restrictOnDelete();
            $table->string('expense_number', 50)->unique();
            $table->enum('category', [
                'operational',
                'maintenance',
                'supplies',
                'utilities',
                'marketing',
                'other',
            ])->default('operational');
            $table->string('title', 255);
            $table->decimal('amount', 12, 2);
            $table->enum('payment_source', [
                'cash_drawer',
                'bank_transfer',
                'petty_cash',
            ])->default('cash_drawer');
            $table->enum('status', [
                'draft',
                'pending_approval',
                'approved',
                'rejected',
                'disbursed',
            ])->default('approved');
            $table->uuid('requested_by');
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->uuid('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('disbursed_at')->nullable();
            $table->string('receipt_image_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'branch_id']);
            $table->index(['branch_id', 'disbursed_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_expenses');
    }
};
