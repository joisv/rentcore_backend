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
        // 1. affiliates (Branches)
        Schema::table('affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('affiliates', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 2. customers
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 3. asset_categories
        Schema::table('asset_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_categories', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 4. asset_models
        Schema::table('asset_models', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_models', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 5. assets
        Schema::table('assets', function (Blueprint $table) {
            if (!Schema::hasColumn('assets', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 6. vouchers
        Schema::table('vouchers', function (Blueprint $table) {
            if (!Schema::hasColumn('vouchers', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 7. bookings
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 8. payment_transactions
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_transactions', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 9. inventory_transfers
        Schema::table('inventory_transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_transfers', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });

        // 10. audit_logs
        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_logs', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'audit_logs',
            'inventory_transfers',
            'payment_transactions',
            'bookings',
            'vouchers',
            'assets',
            'asset_models',
            'asset_categories',
            'customers',
            'affiliates',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'business_id')) {
                    $blueprint->dropForeign([$table . '_business_id_foreign']);
                    $blueprint->dropColumn('business_id');
                }
            });
        }
    }
};
