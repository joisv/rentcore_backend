<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bookings') && !Schema::hasColumn('bookings', 'idempotency_key')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('idempotency_key', 64)->nullable()->after('booking_code');
                $table->unique(['business_id', 'idempotency_key'], 'uq_bookings_biz_idempotency');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'idempotency_key')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique('uq_bookings_biz_idempotency');
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
