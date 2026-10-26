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
        Schema::table('rental_extensions', function (Blueprint $table) {
            if (!Schema::hasColumn('rental_extensions', 'booking_item_id')) {
                $table->foreignId('booking_item_id')
                    ->nullable()
                    ->after('booking_id')
                    ->constrained('booking_items')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('rental_extensions', 'adjustment_type')) {
                $table->string('adjustment_type', 20)->default('increase')->after('booking_item_id');
            }

            if (!Schema::hasColumn('rental_extensions', 'hours_delta')) {
                $table->integer('hours_delta')->nullable()->after('adjustment_type');
            }

            if (!Schema::hasColumn('rental_extensions', 'fee_delta')) {
                $table->decimal('fee_delta', 12, 2)->nullable()->after('hours_delta');
            }

            if (!Schema::hasColumn('rental_extensions', 'reason')) {
                $table->string('reason', 255)->nullable()->after('fee_delta');
            }

            if (!Schema::hasColumn('rental_extensions', 'manager_approved_by')) {
                $table->uuid('manager_approved_by')
                    ->nullable()
                    ->after('reason');
                $table->foreign('manager_approved_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rental_extensions', function (Blueprint $table) {
            $table->dropForeign(['booking_item_id']);
            $table->dropForeign(['manager_approved_by']);
            $table->dropColumn([
                'booking_item_id',
                'adjustment_type',
                'hours_delta',
                'fee_delta',
                'reason',
                'manager_approved_by',
            ]);
        });
    }
};
