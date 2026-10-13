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
        // 1. inventory_transfers
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_code', 40)->unique();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('from_affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('to_affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->uuid('sent_by');
            $table->foreign('sent_by')->references('id')->on('users')->restrictOnDelete();
            $table->uuid('received_by')->nullable();
            $table->foreign('received_by')->references('id')->on('users')->restrictOnDelete();

            $table->enum('status', ['pending', 'in_transit', 'received', 'cancelled'])->default('in_transit');
            $table->text('notes')->nullable();
            $table->dateTime('sent_at');
            $table->dateTime('received_at')->nullable();
            $table->timestamps();

            $table->index(['from_affiliate_id', 'to_affiliate_id', 'status'], 'idx_transfers_from_to_status');
        });

        // 2. audit_logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->nullOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->string('entity_id', 100);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
            $table->index('created_at', 'idx_audit_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('inventory_transfers');
    }
};
