<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRON-1..9. Run mode, timezone, overlap policy, retries and alerting for
 * scheduled jobs; attempt, retry time and long-run alert flag for each run.
 *
 * run_mode: container (exec in the app's container), oneoff (fresh container from
 * the app's image), host (the server's shell, admins only) or http (call a URL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_jobs', function (Blueprint $t) {
            $t->string('run_mode')->nullable();
            $t->uuid('server_id')->nullable();
            $t->string('timezone', 64)->default('UTC');
            $t->string('overlap')->default('skip');            // skip|parallel|stop
            $t->unsignedTinyInteger('retries')->default(0);
            $t->unsignedInteger('retry_backoff')->default(60);  // seconds, doubled per attempt
            $t->unsignedInteger('long_after')->nullable();      // seconds before a run counts as long-running
            $t->unsignedInteger('failure_streak')->default(0);
            $t->boolean('alert_email')->default(true);
            $t->boolean('alert_webhook')->default(true);
        });
        Schema::table('scheduled_job_runs', function (Blueprint $t) {
            $t->unsignedTinyInteger('attempt')->default(1);
            $t->timestamp('retry_at')->nullable();
            $t->boolean('long_alerted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_jobs', fn (Blueprint $t) => $t->dropColumn(['run_mode', 'server_id', 'timezone', 'overlap', 'retries', 'retry_backoff', 'long_after', 'failure_streak', 'alert_email', 'alert_webhook']));
        Schema::table('scheduled_job_runs', fn (Blueprint $t) => $t->dropColumn(['attempt', 'retry_at', 'long_alerted']));
    }
};
