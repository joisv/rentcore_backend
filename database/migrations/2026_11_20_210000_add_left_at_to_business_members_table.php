<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('business_members') || Schema::hasColumn('business_members', 'left_at')) {
            return;
        }

        Schema::table('business_members', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('business_members') || !Schema::hasColumn('business_members', 'left_at')) {
            return;
        }

        Schema::table('business_members', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }
};
