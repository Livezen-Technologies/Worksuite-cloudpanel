<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise updates: one row per update run of a deployment (manual, scheduled one-off or automatic),
 * and the deployment's update settings (mode, channel, maintenance window). A run always takes a project
 * backup first, redeploys on the new tag, and rolls back to the previous tag when the new one fails.
 * dedupe_key makes the automatic run idempotent: one per deployment per target version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_updates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->uuid('enterprise_deployment_id')->index();
            $t->string('from_version', 64)->nullable();
            $t->string('to_version', 64);
            $t->string('trigger', 16); // manual|scheduled|auto
            $t->string('dedupe_key', 64);
            $t->string('status', 20)->index(); // scheduled|backing_up|deploying|rolling_back|succeeded|failed|rolled_back|cancelled
            $t->timestamp('scheduled_for')->nullable()->index();
            $t->uuid('backup_job_id')->nullable();
            $t->uuid('requested_by')->nullable();
            $t->string('requested_by_name', 191)->nullable();
            $t->string('reason', 500)->nullable();
            $t->text('error')->nullable();
            $t->json('log')->nullable();
            $t->json('job_ids')->nullable(); // the redeploy jobs being watched (new tag, then the rollback)
            $t->timestamp('started_at')->nullable();
            $t->timestamp('deploy_started_at')->nullable();
            $t->timestamp('rollback_started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['enterprise_deployment_id', 'to_version', 'dedupe_key'], 'enterprise_updates_once');
        });
        Schema::table('enterprise_deployments', fn (Blueprint $t) => $t->json('update_settings')->nullable());
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_updates');
        Schema::table('enterprise_deployments', fn (Blueprint $t) => $t->dropColumn('update_settings'));
    }
};
