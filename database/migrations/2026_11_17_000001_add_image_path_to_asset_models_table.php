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
        Schema::table('asset_models', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_models', 'image_path')) {
                $table->string('image_path', 255)->nullable()->after('slug');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_models', function (Blueprint $table) {
            if (Schema::hasColumn('asset_models', 'image_path')) {
                $table->dropColumn('image_path');
            }
        });
    }
};
