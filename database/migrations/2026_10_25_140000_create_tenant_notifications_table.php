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
        Schema::create('tenant_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 32); // 'transfer', 'rental', 'cashier', 'membership', 'system'
            $table->string('type', 64); // 'transfer.requested', 'transfer.approved', etc.
            $table->string('title');
            $table->text('body');
            $table->string('action_route')->nullable(); // e.g. '/transfers', '/bookings/123'
            $table->json('action_payload')->nullable(); // Contextual payload e.g. {'transfer_id': 1}
            $table->string('dedup_key', 191)->nullable()->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'business_id', 'read_at'], 'idx_user_biz_unread');
            $table->index(['business_id', 'category'], 'idx_biz_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_notifications');
    }
};
