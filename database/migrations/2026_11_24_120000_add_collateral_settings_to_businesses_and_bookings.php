<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'require_collateral')) {
                $table->boolean('require_collateral')
                    ->default(true)
                    ->after('max_addons_per_rental');
            }
            if (!Schema::hasColumn('businesses', 'collateral_types')) {
                $table->json('collateral_types')
                    ->nullable()
                    ->after('require_collateral');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'collateral_type_id')) {
                $table->string('collateral_type_id', 64)
                    ->nullable()
                    ->after('collateral_type');
            }
            if (!Schema::hasColumn('bookings', 'collateral_type_name')) {
                $table->string('collateral_type_name', 100)
                    ->nullable()
                    ->after('collateral_type_id');
            }
            if (!Schema::hasColumn('bookings', 'collateral_deposit_amount')) {
                $table->decimal('collateral_deposit_amount', 12, 2)
                    ->nullable()
                    ->after('collateral_storage_slot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('bookings', 'collateral_type_id') ? 'collateral_type_id' : null,
                Schema::hasColumn('bookings', 'collateral_type_name') ? 'collateral_type_name' : null,
                Schema::hasColumn('bookings', 'collateral_deposit_amount') ? 'collateral_deposit_amount' : null,
            ]));
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('businesses', 'require_collateral') ? 'require_collateral' : null,
                Schema::hasColumn('businesses', 'collateral_types') ? 'collateral_types' : null,
            ]));
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
