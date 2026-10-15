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
        // 1. Enhance users table for Google OAuth & Platform Administration
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id', 100)->nullable()->unique()->after('email');
            }
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url', 255)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'is_platform_admin')) {
                $table->boolean('is_platform_admin')->default(false)->after('is_active');
            }
            // Allow password to be nullable for social logins
            $table->string('password')->nullable()->change();
        });

        // 2. businesses table
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->string('logo_path', 255)->nullable();
            $table->uuid('owner_id');
            $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'idx_businesses_active');
        });

        // 3. business_members table
        Schema::create('business_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'staff'])->default('staff');
            $table->boolean('is_active')->default(true);
            $table->uuid('invited_by')->nullable();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id'], 'uq_business_user');
            $table->index(['user_id', 'is_active'], 'idx_bm_user_active');
        });

        // 4. branch_members table (branch-specific staff access)
        Schema::create('branch_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_member_id')->constrained('business_members')->cascadeOnDelete();
            $table->foreignId('affiliate_id')->constrained('affiliates')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['business_member_id', 'affiliate_id'], 'uq_member_branch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_members');
        Schema::dropIfExists('business_members');
        Schema::dropIfExists('businesses');

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
            $table->dropColumn(['is_platform_admin', 'avatar_url', 'google_id']);
        });
    }
};
