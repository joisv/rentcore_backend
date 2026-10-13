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
        // 1. customers
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 30)->unique();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->text('blacklist_reason')->nullable();
            $table->timestamps();

            $table->index('phone', 'idx_customers_phone');
        });

        // 2. customer_identities
        Schema::create('customer_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('identity_type', ['KTP', 'SIM', 'KK', 'PASSPORT', 'KARTU_PELAJAR']);
            $table->string('identity_number', 100);
            $table->string('file_path', 255)->nullable();
            $table->boolean('is_verified')->default(true);
            $table->timestamps();

            $table->index('customer_id', 'idx_identities_customer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_identities');
        Schema::dropIfExists('customers');
    }
};
