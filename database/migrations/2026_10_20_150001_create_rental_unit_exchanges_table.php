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
        if (Schema::hasTable('rental_unit_exchanges')) {
            return;
        }

        Schema::create('rental_unit_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();
            $table->foreignId('old_asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('new_asset_id')->constrained('assets')->restrictOnDelete();

            // Diagnostic & Reason
            $table->enum('reported_issue_category', [
                'camera',
                'screen',
                'battery',
                'network',
                'audio',
                'system',
                'other',
            ]);
            $table->text('customer_complaint');
            $table->text('staff_diagnosis')->nullable();
            $table->enum('old_unit_condition_grade', ['A', 'B', 'C'])->default('B');
            $table->boolean('icloud_signout_verified')->default(true);

            // Asset Disposition
            $table->enum('old_asset_disposition', ['maintenance', 'quarantine', 'ready'])->default('maintenance');

            // Financials
            $table->decimal('old_unit_rate', 12, 2);
            $table->decimal('new_unit_rate', 12, 2);
            $table->decimal('price_differential', 12, 2)->default(0.00);
            $table->boolean('fee_waived')->default(false);
            $table->string('waiver_reason', 255)->nullable();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();

            // Personnel & Audit
            $table->uuid('handled_by');
            $table->foreign('handled_by')->references('id')->on('users')->restrictOnDelete();
            $table->uuid('manager_approved_by')->nullable();
            $table->foreign('manager_approved_by')->references('id')->on('users')->nullOnDelete();

            $table->dateTime('exchanged_at');
            $table->timestamps();

            $table->index('booking_id', 'idx_exchanges_booking');
            $table->index(['branch_id', 'exchanged_at'], 'idx_exchanges_branch_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rental_unit_exchanges');
    }
};
