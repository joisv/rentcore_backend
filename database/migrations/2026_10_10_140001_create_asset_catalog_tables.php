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
        // 1. asset_categories
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. asset_models
        Schema::create('asset_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->string('brand', 100);
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->string('storage_capacity', 50);
            $table->string('color', 50);
            $table->json('standard_accessories')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'is_active'], 'idx_models_category');
        });

        // 3. assets
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates')->restrictOnDelete();
            $table->foreignId('model_id')->constrained('asset_models')->restrictOnDelete();
            $table->string('asset_code', 50)->unique();
            $table->string('serial_number', 100)->unique();
            $table->string('imei_1', 50)->nullable();
            $table->string('imei_2', 50)->nullable();
            $table->enum('status', ['ready', 'reserved', 'rented', 'maintenance', 'in_transit', 'retired', 'lost'])->default('ready');
            $table->enum('condition_grade', ['A', 'B', 'C'])->default('A');
            $table->integer('battery_health')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status'], 'idx_assets_affiliate_status');
            $table->index('model_id', 'idx_assets_model');
        });

        // 4. pricing_tiers
        Schema::create('pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('asset_models')->cascadeOnDelete();
            $table->integer('duration_hours');
            $table->decimal('price', 12, 2);
            $table->decimal('extension_hourly_rate', 12, 2)->default(15000.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['model_id', 'duration_hours'], 'uq_model_duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_tiers');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_models');
        Schema::dropIfExists('asset_categories');
    }
};
