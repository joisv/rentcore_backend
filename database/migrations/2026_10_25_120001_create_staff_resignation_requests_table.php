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
        if (Schema::hasTable('staff_resignation_requests')) {
            return;
        }

        Schema::create('staff_resignation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('business_member_id')->constrained('business_members')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->text('reason')->nullable();
            $table->text('decision_notes')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status'], 'idx_srr_biz_status');
            $table->index(['user_id', 'status'], 'idx_srr_user_status');
            $table->index(['business_member_id', 'status'], 'idx_srr_bm_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_resignation_requests');
    }
};
