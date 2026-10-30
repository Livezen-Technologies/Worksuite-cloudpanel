<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UCRON / CRON-3: jobs that run in an app or call a URL, and their run history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_jobs', function (Blueprint $t) {
            $t->uuid('application_id')->nullable()->index();
            $t->unsignedInteger('timeout')->default(600);
        });
        Schema::create('scheduled_job_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('organization_id')->index();
            $t->uuid('scheduled_job_id')->index();
            $t->string('status');                 // running, succeeded, failed
            $t->integer('exit_code')->nullable();
            $t->text('output')->nullable();       // last 64 KB
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('source')->default('schedule'); // schedule, manual
            $t->uuid('job_id')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_job_runs');
        Schema::table('scheduled_jobs', fn (Blueprint $t) => $t->dropColumn(['application_id', 'timeout']));
    }
};
