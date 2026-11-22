<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENT-MAIL: one row per enterprise run that gets a single summary email (EnterpriseRunReportService):
 * provisioning, an update run, a manual redeploy/restart/start/stop, or a backup set of the deployment.
 * run_key is unique, so each run is emailed once however often the scheduler sees it finish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_run_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->uuid('enterprise_deployment_id')->index();
            $t->string('kind', 20); // provision|update|redeploy|restart|start|stop|backup
            $t->string('run_key', 191)->unique();
            $t->string('status', 20)->index(); // running|succeeded|failed|partial|rolled_back
            $t->string('triggered_by', 191)->nullable();
            $t->string('reason', 500)->nullable();
            $t->json('job_ids')->nullable();
            $t->uuid('backup_job_id')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->unsignedInteger('recipients')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_run_reports');
    }
};
