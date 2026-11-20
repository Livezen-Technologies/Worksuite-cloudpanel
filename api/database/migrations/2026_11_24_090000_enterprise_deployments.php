<?php

use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise provisioning (docs/ENTERPRISE_API.md): Cloud Admin deploys a
 * dedicated WorkSuite Server per enterprise customer.
 *
 *  enterprise_deployments  what Cloud Admin asked for, mapped to the project, database, Redis,
 *                          app and worker that serve it; the step timeline; the webhook secret
 *  enterprise_webhooks     outbox of signed status events back to Cloud Admin
 *  projects.tags           "enterprise" marks these projects in the panel's Projects list
 *
 * Also re-runs the reference seeder so the new `enterprise.deploy` permission and the
 * `enterprise_service` role reach role_permissions on an existing install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->json('tags')->nullable());

        Schema::create('enterprise_deployments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('server_id')->nullable()->index();
            $t->string('external_id', 64)->unique();
            $t->string('customer_name', 160);
            $t->string('slug', 40)->unique();
            $t->string('version', 60);
            $t->string('size', 20);
            $t->string('status', 20)->default('queued')->index();
            $t->string('status_reason', 255)->nullable();
            $t->json('steps')->nullable();
            $t->string('hostname')->nullable();
            $t->string('custom_domain')->nullable();
            $t->string('callback_url', 500)->nullable();
            $t->text('callback_secret');   // encrypted cast
            $t->uuid('database_id')->nullable();
            $t->uuid('redis_id')->nullable();
            $t->uuid('app_application_id')->nullable();
            $t->uuid('worker_application_id')->nullable();
            $t->uuid('bootstrap_job_id')->nullable();
            $t->uuid('status_job_id')->nullable();
            $t->json('app_status')->nullable();
            $t->json('bootstrap_result')->nullable();
            $t->string('reported_hash', 64)->nullable();
            $t->timestamp('status_checked_at')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('purged_at')->nullable();
            $t->timestamps();
        });

        Schema::create('enterprise_webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('enterprise_deployment_id')->constrained('enterprise_deployments')->cascadeOnDelete();
            $t->string('event', 60);
            $t->json('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->string('last_error', 255)->nullable();
            $t->timestamps();
        });

        if (DB::table('roles')->count() > 0) {
            (new ReferenceDataSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_webhooks');
        Schema::dropIfExists('enterprise_deployments');
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('tags'));
    }
};
