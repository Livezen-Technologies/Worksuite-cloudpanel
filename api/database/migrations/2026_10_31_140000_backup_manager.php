<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BKM-003/006/007/008. Daily/weekly/monthly retention, object lock and the last
 * destination check on a policy; scope, mode and app handling on a restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_policies', function (Blueprint $t) {
            $t->unsignedSmallInteger('keep_daily')->nullable();
            $t->unsignedSmallInteger('keep_weekly')->nullable();
            $t->unsignedSmallInteger('keep_monthly')->nullable();
            $t->unsignedSmallInteger('object_lock_days')->nullable();
            $t->unsignedInteger('capacity_gb')->nullable();      // for the 85 % warning
            $t->json('last_probe')->nullable();                 // reachable, snapshots, stored_bytes, message
            $t->timestamp('probed_at')->nullable();
        });
        Schema::table('restore_jobs', function (Blueprint $t) {
            $t->string('scope')->default('everything');         // everything|files|database
            $t->string('mode')->default('replace');             // replace|side_by_side
            $t->boolean('stop_app')->default(false);
            $t->boolean('health_check')->default(false);
            $t->string('target_path')->nullable();
            $t->text('message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_policies', fn (Blueprint $t) => $t->dropColumn(['keep_daily', 'keep_weekly', 'keep_monthly', 'object_lock_days', 'capacity_gb', 'last_probe', 'probed_at']));
        Schema::table('restore_jobs', fn (Blueprint $t) => $t->dropColumn(['scope', 'mode', 'stop_app', 'health_check', 'target_path', 'message']));
    }
};
