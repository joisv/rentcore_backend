<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->uuid('recipient_id')->nullable()->after('received_by');
            $table->foreign('recipient_id')->references('id')->on('users')->restrictOnDelete();

            $table->uuid('approved_by')->nullable()->after('recipient_id');
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            $table->dateTime('approved_at')->nullable()->after('approved_by');

            $table->uuid('rejected_by')->nullable()->after('approved_at');
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();

            $table->dateTime('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_transfers MODIFY COLUMN status ENUM('pending', 'in_transit', 'received', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_transfers MODIFY COLUMN status ENUM('pending', 'in_transit', 'received', 'cancelled') NOT NULL DEFAULT 'in_transit'");
        }

        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropForeign(['recipient_id']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn([
                'recipient_id',
                'approved_by',
                'approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_reason',
            ]);
        });
    }
};
