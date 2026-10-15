<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Convert global single-column unique constraints to compound tenant-scoped unique constraints.
     */
    public function up(): void
    {
        // 1. Customers: phone is unique per business
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_phone_unique');
            $table->unique(['business_id', 'phone'], 'uq_customers_biz_phone');
        });

        // 2. Assets: asset_code and serial_number unique per business
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique('assets_asset_code_unique');
            $table->dropUnique('assets_serial_number_unique');
            $table->unique(['business_id', 'asset_code'], 'uq_assets_biz_code');
            $table->unique(['business_id', 'serial_number'], 'uq_assets_biz_serial');
        });

        // 3. Vouchers: promo code unique per business
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique('vouchers_code_unique');
            $table->unique(['business_id', 'code'], 'uq_vouchers_biz_code');
        });

        // 4. Affiliates (Branches): code and slug unique per business
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropUnique('affiliates_code_unique');
            $table->dropUnique('affiliates_slug_unique');
            $table->unique(['business_id', 'code'], 'uq_affiliates_biz_code');
            $table->unique(['business_id', 'slug'], 'uq_affiliates_biz_slug');
        });

        // 5. Asset Categories: name and slug unique per business
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropUnique('asset_categories_name_unique');
            $table->dropUnique('asset_categories_slug_unique');
            $table->unique(['business_id', 'name'], 'uq_categories_biz_name');
            $table->unique(['business_id', 'slug'], 'uq_categories_biz_slug');
        });

        // 6. Asset Models: slug unique per business
        Schema::table('asset_models', function (Blueprint $table) {
            $table->dropUnique('asset_models_slug_unique');
            $table->unique(['business_id', 'slug'], 'uq_models_biz_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_models', function (Blueprint $table) {
            $table->dropUnique('uq_models_biz_slug');
            $table->unique('slug', 'asset_models_slug_unique');
        });

        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropUnique('uq_categories_biz_name');
            $table->dropUnique('uq_categories_biz_slug');
            $table->unique('name', 'asset_categories_name_unique');
            $table->unique('slug', 'asset_categories_slug_unique');
        });

        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropUnique('uq_affiliates_biz_code');
            $table->dropUnique('uq_affiliates_biz_slug');
            $table->unique('code', 'affiliates_code_unique');
            $table->unique('slug', 'affiliates_slug_unique');
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique('uq_vouchers_biz_code');
            $table->unique('code', 'vouchers_code_unique');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique('uq_assets_biz_code');
            $table->dropUnique('uq_assets_biz_serial');
            $table->unique('asset_code', 'assets_asset_code_unique');
            $table->unique('serial_number', 'assets_serial_number_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('uq_customers_biz_phone');
            $table->unique('phone', 'customers_phone_unique');
        });
    }
};
