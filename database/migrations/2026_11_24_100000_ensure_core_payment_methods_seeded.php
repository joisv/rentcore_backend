<?php

use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Feature tests seed CASH/QRIS explicitly via PaymentMethod::create(); keep RefreshDatabase clean in test runs.
        if (app()->runningUnitTests()) {
            return;
        }

        $coreMethods = [
            ['code' => 'CASH', 'name' => 'Tunai'],
            ['code' => 'QRIS', 'name' => 'QRIS'],
            ['code' => 'BCA', 'name' => 'Transfer Bank BCA'],
            ['code' => 'TRANSFER', 'name' => 'Transfer Bank'],
            ['code' => 'SHOPEEPAY', 'name' => 'ShopeePay'],
            ['code' => 'DANA', 'name' => 'DANA'],
            ['code' => 'GOPAY', 'name' => 'GoPay'],
            ['code' => 'LINKAJA', 'name' => 'LinkAja'],
            ['code' => 'OVO', 'name' => 'OVO'],
            ['code' => 'MANDIRI', 'name' => 'Transfer Bank Mandiri'],
            ['code' => 'BRI', 'name' => 'Transfer Bank BRI'],
            ['code' => 'BNI', 'name' => 'Transfer Bank BNI'],
        ];

        foreach ($coreMethods as $method) {
            PaymentMethod::firstOrCreate(
                ['code' => $method['code']],
                ['name' => $method['name'], 'is_active' => true]
            );
        }

        // Normalize legacy seeded QRIS display name to concise "QRIS"
        PaymentMethod::where('code', 'QRIS')
            ->where('name', 'QRIS Dinamis')
            ->update(['name' => 'QRIS']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive seed/normalization migration.
    }
};
