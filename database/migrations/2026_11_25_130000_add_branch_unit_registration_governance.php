<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('businesses') && !Schema::hasColumn('businesses', 'branch_unit_registration_policy')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->string('branch_unit_registration_policy', 40)
                    ->default('self_service')
                    ->after('max_addons_per_rental');
            });
        }

        if (Schema::hasTable('assets')) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement(
                    "ALTER TABLE assets MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'ready'"
                );
            } else {
                // SQLite / PostgreSQL: drop old enum check constraint cleanly via change()
                Schema::table('assets', function (Blueprint $table) {
                    $table->string('status', 32)->default('ready')->change();
                });
            }

            Schema::table('assets', function (Blueprint $table) {
                if (!Schema::hasColumn('assets', 'submitted_by')) {
                    $table->uuid('submitted_by')->nullable()->after('notes');
                }
                if (!Schema::hasColumn('assets', 'approved_by')) {
                    $table->uuid('approved_by')->nullable()->after('submitted_by');
                }
                if (!Schema::hasColumn('assets', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }
                if (!Schema::hasColumn('assets', 'rejected_by')) {
                    $table->uuid('rejected_by')->nullable()->after('approved_at');
                }
                if (!Schema::hasColumn('assets', 'rejected_at')) {
                    $table->timestamp('rejected_at')->nullable()->after('rejected_by');
                }
                if (!Schema::hasColumn('assets', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('rejected_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('businesses') && Schema::hasColumn('businesses', 'branch_unit_registration_policy')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('branch_unit_registration_policy');
            });
        }

        if (Schema::hasTable('assets')) {
            Schema::table('assets', function (Blueprint $table) {
                $cols = array_filter([
                    'submitted_by',
                    'approved_by',
                    'approved_at',
                    'rejected_by',
                    'rejected_at',
                    'rejection_reason',
                ], fn ($col) => Schema::hasColumn('assets', $col));

                if (!empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
