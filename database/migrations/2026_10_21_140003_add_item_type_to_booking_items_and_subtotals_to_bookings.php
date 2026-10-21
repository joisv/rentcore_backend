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
        Schema::table('booking_items', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_items', 'item_type')) {
                $table->string('item_type', 20)->default('primary')->after('asset_id');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'primary_subtotal')) {
                $table->decimal('primary_subtotal', 12, 2)->nullable()->after('subtotal_amount');
            }
            if (!Schema::hasColumn('bookings', 'addon_subtotal')) {
                $table->decimal('addon_subtotal', 12, 2)->default(0.00)->after('primary_subtotal');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['primary_subtotal', 'addon_subtotal']);
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn('item_type');
        });
    }
};
