<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner provisioning (docs/PARTNER_API.md): WorkSuite Cloud deploys
 * WorkSuite Business / CMS here for its customers.
 *
 *  partner_clients        a scoped server-to-server credential (HMAC secret, encrypted at rest)
 *  partner_accounts       one panel organisation per partner customer account
 *  partner_deployments    what the partner asked for, mapped to the application that serves it
 *  partner_requests       Idempotency-Key replay store, per client
 *  partner_webhooks       outbox of signed status events back to the partner
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('key_id', 64)->unique();
            $t->text('secret');            // encrypted cast
            $t->text('webhook_secret');    // encrypted cast
            $t->string('webhook_url')->nullable();
            $t->json('scopes');
            $t->json('allowed_ips')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });

        Schema::create('partner_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_client_id')->constrained('partner_clients')->cascadeOnDelete();
            $t->string('external_account_id', 64);
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('service_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('name');
            $t->timestamps();
            $t->unique(['partner_client_id', 'external_account_id']);
        });

        Schema::create('partner_deployments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_client_id')->constrained('partner_clients')->cascadeOnDelete();
            $t->foreignUuid('partner_account_id')->constrained('partner_accounts')->cascadeOnDelete();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('application_id')->nullable()->index();
            $t->string('external_id', 64);
            $t->string('product', 20);
            $t->string('workspace_name');
            $t->string('subdomain', 40);
            $t->string('custom_domain')->nullable();
            $t->string('region', 60)->nullable();
            $t->string('size', 20);
            $t->unsignedInteger('storage_gb');
            $t->string('version', 60)->nullable();
            $t->string('admin_email');
            $t->string('admin_name')->nullable();
            // provisioning|running|stopped|suspended|failed|terminated
            $t->string('status', 20)->default('provisioning');
            $t->string('status_reason')->nullable();
            $t->uuid('last_job_id')->nullable();
            $t->string('reported_hash', 64)->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamp('terminated_at')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->unique(['partner_client_id', 'external_id']);
            $t->index(['status']);
        });

        Schema::create('partner_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('partner_client_id')->constrained('partner_clients')->cascadeOnDelete();
            $t->string('key', 255);
            $t->string('fingerprint', 64);
            $t->unsignedSmallInteger('status')->nullable();
            $t->longText('body')->nullable();
            $t->timestamp('created_at');
            $t->unique(['partner_client_id', 'key']);
        });

        Schema::create('partner_webhooks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_client_id')->constrained('partner_clients')->cascadeOnDelete();
            $t->uuid('partner_deployment_id')->nullable()->index();
            $t->string('event', 60);
            $t->json('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
            $t->index(['delivered_at', 'failed_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhooks');
        Schema::dropIfExists('partner_requests');
        Schema::dropIfExists('partner_deployments');
        Schema::dropIfExists('partner_accounts');
        Schema::dropIfExists('partner_clients');
    }
};
