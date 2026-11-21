<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_configs', function (Blueprint $table) {
            $table->id();
            $table->string('min_android_version')->default('1.0.0');
            $table->string('latest_android_version')->default('1.0.0');
            $table->string('play_store_url')->default('https://play.google.com/store/apps/details?id=com.rentcore.app');
            $table->boolean('maintenance_mode')->default(false);
            $table->text('maintenance_message')->nullable();
            $table->text('force_update_message')->nullable();
            $table->timestamps();
        });

        DB::table('app_configs')->insert([
            'min_android_version' => '1.0.0',
            'latest_android_version' => '1.0.0',
            'play_store_url' => 'https://play.google.com/store/apps/details?id=com.rentcore.app',
            'maintenance_mode' => false,
            'maintenance_message' => null,
            'force_update_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_configs');
    }
};
