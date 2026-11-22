<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The automatic data restore after a rollback (EnterpriseUpdateService::restoreData): {state, backup_id,
 * restore_job_ids, safety_backup_ids, error, started_at, finished_at}. The setting itself lives in
 * enterprise_deployments.update_settings (JSON), so it needs no column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprise_updates', fn (Blueprint $t) => $t->json('data_restore')->nullable()->after('job_ids'));
    }

    public function down(): void
    {
        Schema::table('enterprise_updates', fn (Blueprint $t) => $t->dropColumn('data_restore'));
    }
};
