<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('booking_items')) {
            return;
        }

        Schema::table('booking_items', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_items', 'is_returned')) {
                $table->boolean('is_returned')->default(false)->after('status');
            }
            if (!Schema::hasColumn('booking_items', 'returned_at')) {
                $table->timestamp('returned_at')->nullable()->after('is_returned');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('booking_items')) {
            return;
        }

        Schema::table('booking_items', function (Blueprint $table) {
            if (Schema::hasColumn('booking_items', 'returned_at')) {
                $table->dropColumn('returned_at');
            }
            if (Schema::hasColumn('booking_items', 'is_returned')) {
                $table->dropColumn('is_returned');
            }
        });
    }
};
