<?php

use App\Models\PaymentMethod;
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
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'payment_methods_config')) {
                $table->json('payment_methods_config')->nullable()->after('require_expense_pin');
            }
        });

        Schema::table('affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('affiliates', 'payment_methods_config_override')) {
                $table->json('payment_methods_config_override')->nullable()->after('shift_closing_time_override');
            }
        });

        // Seed standard E-Wallet providers into payment_methods lookup table
        $providers = [
            ['code' => 'SHOPEEPAY', 'name' => 'ShopeePay'],
            ['code' => 'DANA', 'name' => 'DANA'],
            ['code' => 'LINKAJA', 'name' => 'LinkAja'],
            ['code' => 'OVO', 'name' => 'OVO'],
        ];

        foreach ($providers as $provider) {
            PaymentMethod::firstOrCreate(
                ['code' => $provider['code']],
                ['name' => $provider['name'], 'is_active' => true]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'payment_methods_config')) {
                $table->dropColumn('payment_methods_config');
            }
        });

        Schema::table('affiliates', function (Blueprint $table) {
            if (Schema::hasColumn('affiliates', 'payment_methods_config_override')) {
                $table->dropColumn('payment_methods_config_override');
            }
        });

        PaymentMethod::whereIn('code', ['SHOPEEPAY', 'DANA', 'LINKAJA', 'OVO'])->delete();
    }
};
