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
        // 1. rental_extensions
        Schema::create('rental_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->uuid('extended_by');
            $table->foreign('extended_by')->references('id')->on('users')->restrictOnDelete();
            $table->dateTime('previous_end_at');
            $table->dateTime('new_end_at');
            $table->integer('additional_hours');
            $table->decimal('additional_fee', 12, 2);
            $table->timestamps();
        });

        // 2. return_inspections
        Schema::create('return_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->uuid('inspected_by');
            $table->foreign('inspected_by')->references('id')->on('users')->restrictOnDelete();
            $table->dateTime('returned_at');

            $table->enum('condition_grade', ['A', 'B', 'C'])->default('A');
            $table->integer('battery_health_final')->nullable();
            $table->json('checklist_results');
            $table->json('accessories_returned');

            $table->integer('late_minutes')->default(0);
            $table->decimal('late_fee', 12, 2)->default(0.00);
            $table->decimal('damage_fee', 12, 2)->default(0.00);
            $table->decimal('total_penalty', 12, 2)->default(0.00);

            $table->decimal('deposit_refunded', 12, 2)->default(0.00);
            $table->text('staff_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_inspections');
        Schema::dropIfExists('rental_extensions');
    }
};
