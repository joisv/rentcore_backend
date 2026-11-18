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
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'rented'");
        } else {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('status', 30)->default('rented')->change();
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'asset_model_id')) {
                $table->foreignId('asset_model_id')
                    ->nullable()
                    ->after('affiliate_id')
                    ->constrained('asset_models')
                    ->nullOnDelete();
                $table->index(
                    ['business_id', 'affiliate_id', 'asset_model_id', 'status'],
                    'idx_bookings_biz_branch_model_status'
                );
            }

            if (!Schema::hasColumn('bookings', 'asset_unit_id')) {
                $table->foreignId('asset_unit_id')
                    ->nullable()
                    ->after('asset_model_id')
                    ->constrained('assets')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('bookings', 'grace_expires_at')) {
                $table->timestamp('grace_expires_at')->nullable()->after('end_at');
            }

            if (!Schema::hasColumn('bookings', 'no_show_at')) {
                $table->timestamp('no_show_at')->nullable()->after('grace_expires_at');
            }

            if (!Schema::hasColumn('bookings', 'forfeited_dp_amount')) {
                $table->decimal('forfeited_dp_amount', 12, 2)->default(0.00)->after('no_show_at');
            }

            if (!Schema::hasColumn('bookings', 'is_overdue_pickup')) {
                $table->boolean('is_overdue_pickup')->default(false)->after('forfeited_dp_amount');
            }

            $table->index(['status', 'grace_expires_at'], 'idx_bookings_status_grace');
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id')->nullable()->change();

            if (!Schema::hasColumn('booking_items', 'asset_model_id')) {
                $table->foreignId('asset_model_id')
                    ->nullable()
                    ->after('asset_id')
                    ->constrained('asset_models')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            if (Schema::hasColumn('booking_items', 'asset_model_id')) {
                $table->dropConstrainedForeignId('asset_model_id');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_status_grace');

            if (Schema::hasColumn('bookings', 'is_overdue_pickup')) {
                $table->dropColumn('is_overdue_pickup');
            }
            if (Schema::hasColumn('bookings', 'forfeited_dp_amount')) {
                $table->dropColumn('forfeited_dp_amount');
            }
            if (Schema::hasColumn('bookings', 'no_show_at')) {
                $table->dropColumn('no_show_at');
            }
            if (Schema::hasColumn('bookings', 'grace_expires_at')) {
                $table->dropColumn('grace_expires_at');
            }
            if (Schema::hasColumn('bookings', 'asset_unit_id')) {
                $table->dropConstrainedForeignId('asset_unit_id');
            }
            if (Schema::hasColumn('bookings', 'asset_model_id')) {
                $table->dropIndex('idx_bookings_biz_branch_model_status');
                $table->dropConstrainedForeignId('asset_model_id');
            }
        });
    }
};
