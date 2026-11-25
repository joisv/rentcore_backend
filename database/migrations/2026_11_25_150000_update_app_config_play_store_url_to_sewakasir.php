<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_configs')) {
            return;
        }

        DB::table('app_configs')
            ->whereIn('play_store_url', [
                'https://play.google.com/store/apps/details?id=com.rentcore.app',
                'https://play.google.com/store/apps/details?id=com.rentcore.rentcore_mobile',
            ])
            ->update([
                'play_store_url' => 'https://play.google.com/store/apps/details?id=id.sewakasir.mobile',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_configs')) {
            return;
        }

        DB::table('app_configs')
            ->where('play_store_url', 'https://play.google.com/store/apps/details?id=id.sewakasir.mobile')
            ->update([
                'play_store_url' => 'https://play.google.com/store/apps/details?id=com.rentcore.app',
                'updated_at' => now(),
            ]);
    }
};
