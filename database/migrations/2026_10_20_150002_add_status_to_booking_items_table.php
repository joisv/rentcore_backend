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
        if (!Schema::hasTable('booking_items')) {
            return;
        }

        Schema::table('booking_items', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_items', 'status')) {
                $table->enum('status', ['active', 'exchanged', 'returned'])->default('active')->after('duration_value');
            }
            if (!Schema::hasColumn('booking_items', 'exchanged_at')) {
                $table->dateTime('exchanged_at')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('booking_items')) {
            return;
        }

        Schema::table('booking_items', function (Blueprint $table) {
            if (Schema::hasColumn('booking_items', 'exchanged_at')) {
                $table->dropColumn('exchanged_at');
            }
            if (Schema::hasColumn('booking_items', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
