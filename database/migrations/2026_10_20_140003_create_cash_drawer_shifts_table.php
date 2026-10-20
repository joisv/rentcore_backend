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
        if (Schema::hasTable('cash_drawer_shifts')) {
            return;
        }

        Schema::create('cash_drawer_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('affiliates')->restrictOnDelete();
            $table->string('shift_number', 50)->unique();
            $table->uuid('opened_by');
            $table->foreign('opened_by')->references('id')->on('users')->restrictOnDelete();
            $table->uuid('closed_by')->nullable();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_cash_float', 12, 2)->default(0.00);
            $table->decimal('expected_cash_total', 12, 2)->nullable();
            $table->decimal('actual_cash_counted', 12, 2)->nullable();
            $table->decimal('cash_difference', 12, 2)->nullable();
            $table->text('difference_reason')->nullable();
            $table->enum('status', ['open', 'closed', 'reconciled'])->default('open');
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_drawer_shifts');
    }
};
