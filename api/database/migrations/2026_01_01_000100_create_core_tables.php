<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core tables from SRS 7.1. Every tenant row carries organization_id.
 */
return new class extends Migration
{
    private function tenant(Blueprint $t): void
    {
        $t->uuid('id')->primary();
        $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
    }

    public function up(): void
    {
        // Identity
        Schema::create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('default_region')->nullable();
            $t->json('settings')->nullable();
            $t->string('billing_email')->nullable();
            $t->string('payment_customer_id')->nullable();
            $t->json('payment_method')->nullable();
            $t->string('last_audit_hash', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->unique();
            $t->string('label');
            $t->string('scope')->default('platform'); // platform|customer|both
            $t->text('description')->nullable();
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->unique();
            $t->string('label');
            $t->string('group');
            $t->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $t->string('constraint')->default('all'); // all|own_org|assigned|read
            $t->boolean('requires_approval')->default(false);
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('memberships', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('role');
            $t->string('status')->default('active'); // active|invited
            $t->string('invite_token_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });
        Schema::create('passkeys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('name');
            $t->text('credential_id');
            $t->text('public_key');
            $t->timestamps();
        });

        // Infrastructure
        Schema::create('regions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('server_groups', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->json('labels')->nullable();
            $t->timestamps();
        });
        Schema::create('servers', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_group_id')->nullable()->constrained('server_groups')->nullOnDelete();
            $t->string('name');
            $t->string('hostname')->nullable();
            $t->string('ip')->nullable();
            $t->string('environment')->default('production');
            $t->string('region')->nullable();
            $t->json('tags')->nullable();
            $t->string('provider')->nullable();
            $t->boolean('maintenance')->default(false);
            $t->boolean('shared_pool')->default(false);
            $t->integer('scheduling_weight')->default(100);
            $t->integer('max_containers')->nullable();
            $t->string('os')->nullable();
            $t->string('kernel')->nullable();
            $t->string('arch')->nullable();
            $t->string('docker_version')->nullable();
            $t->string('docker_error')->nullable();
            $t->integer('cpu_cores')->nullable();
            $t->integer('ram_mb')->nullable();
            $t->integer('disk_gb')->nullable();
            $t->float('cpu_pct')->nullable();
            $t->float('ram_pct')->nullable();
            $t->float('disk_pct')->nullable();
            $t->unsignedBigInteger('net_rx')->nullable();
            $t->unsignedBigInteger('net_tx')->nullable();
            $t->string('agent_version')->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
            $t->timestamps();
        });
        Schema::create('enrollment_tokens', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->foreignUuid('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agents', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->unique()->constrained('servers')->cascadeOnDelete();
            $t->string('secret_hash', 64)->unique();
            $t->text('hmac_key');
            $t->string('version')->nullable();
            $t->timestamp('enrolled_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('agent_certificates', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('agent_id')->constrained('agents')->cascadeOnDelete();
            $t->string('fingerprint', 64);
            $t->text('certificate');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        // Docker
        Schema::create('registries', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('type')->default('private'); // dockerhub|ghcr|private
            $t->string('url');
            $t->string('username')->nullable();
            $t->text('credentials')->nullable(); // encrypted
            $t->timestamps();
        });
        Schema::create('containers', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->uuid('application_id')->nullable()->index();
            $t->string('docker_id')->nullable();
            $t->string('name');
            $t->string('image');
            $t->string('state')->default('created');
            $t->string('health')->nullable();
            $t->float('cpu_pct')->nullable();
            $t->float('mem_mb')->nullable();
            $t->json('ports')->nullable();
            $t->json('spec')->nullable();
            $t->json('labels')->nullable();
            $t->text('last_logs')->nullable();
            $t->timestamp('logs_fetched_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('docker_created_at')->nullable();
            $t->timestamps();
            $t->unique(['server_id', 'docker_id']);
        });
        Schema::create('images', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->string('docker_id');
            $t->string('repository')->nullable();
            $t->json('tags')->nullable();
            $t->string('digest')->nullable();
            $t->float('size_mb')->nullable();
            $t->string('scan_status')->default('not_scanned');
            $t->json('scan_result')->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->timestamp('docker_created_at')->nullable();
            $t->timestamps();
            $t->unique(['server_id', 'docker_id']);
        });
        Schema::create('networks', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->string('docker_id')->nullable();
            $t->string('name');
            $t->string('driver')->default('bridge');
            $t->string('scope')->nullable();
            $t->string('subnet')->nullable();
            $t->string('gateway')->nullable();
            $t->boolean('internal')->default(false);
            $t->string('exposure')->default('private');
            $t->string('status')->default('active');
            $t->timestamps();
            $t->unique(['server_id', 'name']);
        });
        Schema::create('storage_nodes', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('type'); // local|nfs|object
            $t->string('endpoint')->nullable();
            $t->timestamps();
        });
        Schema::create('storage_pools', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('storage_node_id')->nullable()->constrained('storage_nodes')->nullOnDelete();
            $t->foreignUuid('server_id')->nullable()->constrained('servers')->cascadeOnDelete();
            $t->string('name');
            $t->string('type'); // local_ssd|nvme|nfs|object
            $t->integer('capacity_gb')->default(0);
            $t->integer('used_gb')->default(0);
            $t->timestamps();
        });
        Schema::create('volumes', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->foreignUuid('storage_pool_id')->nullable()->constrained('storage_pools')->nullOnDelete();
            $t->string('name');
            $t->string('type')->default('volume'); // volume|bind|nfs
            $t->string('driver')->default('local');
            $t->string('mountpoint')->nullable();
            $t->json('options')->nullable();
            $t->integer('quota_gb')->nullable();
            $t->float('size_gb')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->unique(['server_id', 'name']);
        });
        Schema::create('volume_mounts', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('volume_id')->constrained('volumes')->cascadeOnDelete();
            $t->foreignUuid('container_id')->constrained('containers')->cascadeOnDelete();
            $t->string('target');
            $t->boolean('read_only')->default(false);
            $t->timestamps();
        });

        // Applications
        Schema::create('projects', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('environments', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $t->string('name');
            $t->boolean('requires_approval')->default(false);
            $t->timestamps();
        });
        Schema::create('applications', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignUuid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $t->string('name');
            $t->string('kind')->default('admin'); // admin|customer
            $t->string('source_type'); // git|image|dockerfile|compose|template
            $t->text('source')->nullable(); // encrypted (may hold registry credentials)
            $t->string('image')->nullable();
            $t->string('strategy')->default('recreate');
            $t->string('environment')->default('production');
            $t->string('region')->nullable();
            $t->string('size')->nullable();
            $t->integer('port')->nullable();
            $t->string('volume_path')->nullable();
            $t->boolean('requires_approval')->default(false);
            $t->boolean('auto_deploy')->default(false);
            $t->boolean('auto_restart')->default(true);
            $t->string('status')->default('created');
            $t->uuid('current_release_id')->nullable();
            $t->timestamp('last_deployed_at')->nullable();
            $t->timestamp('purge_after')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('application_members', function (Blueprint $t) {
            $t->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->primary(['application_id', 'user_id']);
        });
        Schema::create('env_vars', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $t->string('key');
            $t->text('value')->nullable(); // encrypted
            $t->boolean('secret')->default(false);
            $t->uuid('secret_id')->nullable();
            $t->timestamps();
            $t->unique(['application_id', 'key']);
        });
        Schema::create('deployments', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $t->uuid('job_id')->nullable()->index();
            $t->uuid('server_id')->nullable();
            $t->uuid('release_id')->nullable();
            $t->uuid('rollback_of_release_id')->nullable();
            $t->foreignUuid('user_id')->nullable();
            $t->string('status')->default('queued'); // awaiting_approval|queued|running|succeeded|failed|rolled_back
            $t->string('source')->default('manual'); // manual|webhook|rollback|redeploy
            $t->string('strategy')->default('recreate');
            $t->string('image')->nullable();
            $t->json('logs_tail')->nullable();
            $t->foreignUuid('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('releases', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('application_id')->constrained('applications')->cascadeOnDelete();
            $t->uuid('deployment_id')->nullable();
            $t->integer('version');
            $t->string('image')->nullable();
            $t->string('digest')->nullable();
            $t->json('config');
            $t->string('status')->default('active'); // active|superseded|failed
            $t->timestamp('created_at')->nullable();
            $t->unique(['application_id', 'version']);
        });

        // Compose
        Schema::create('compose_projects', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $t->string('name');
            $t->string('environment')->default('production');
            $t->string('source')->default('editor'); // git|upload|editor
            $t->string('status')->default('draft');
            $t->uuid('current_revision_id')->nullable();
            $t->uuid('running_revision_id')->nullable();
            $t->timestamps();
        });
        Schema::create('compose_revisions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('compose_project_id')->constrained('compose_projects')->cascadeOnDelete();
            $t->integer('number');
            $t->longText('yaml');
            $t->text('env')->nullable(); // encrypted
            $t->foreignUuid('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['compose_project_id', 'number']);
        });
        Schema::create('compose_services', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('compose_project_id')->constrained('compose_projects')->cascadeOnDelete();
            $t->string('name');
            $t->string('image')->nullable();
            $t->json('depends_on')->nullable();
            $t->string('health')->nullable();
            $t->timestamps();
        });

        // Networking
        Schema::create('domains', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name')->unique();
            $t->foreignUuid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $t->integer('target_port')->nullable();
            $t->boolean('wildcard')->default(false);
            $t->boolean('www_redirect')->default(false);
            $t->boolean('platform_subdomain')->default(false);
            $t->string('challenge')->default('http-01');
            $t->string('dns_status')->default('pending'); // pending|verified|failed
            $t->string('https_status')->default('pending'); // pending|issuing|active|failed
            $t->json('options')->nullable(); // redirects, headers, middleware, websocket
            $t->timestamp('dns_checked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('dns_checks', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('domain_id')->constrained('domains')->cascadeOnDelete();
            $t->json('records');
            $t->boolean('ok');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('certificates', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('domain_id')->constrained('domains')->cascadeOnDelete();
            $t->string('issuer')->default('letsencrypt');
            $t->string('status')->default('active');
            $t->json('names')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('routes', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('domain_id')->constrained('domains')->cascadeOnDelete();
            $t->uuid('application_id')->nullable();
            $t->string('path')->default('/');
            $t->integer('port')->nullable();
            $t->json('labels')->nullable();
            $t->timestamps();
        });
        Schema::create('proxy_rules', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('route_id')->constrained('routes')->cascadeOnDelete();
            $t->string('type'); // redirect|header|middleware|websocket
            $t->json('config');
            $t->timestamps();
        });

        // Databases (DB-001)
        Schema::create('databases', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $t->uuid('container_id')->nullable();
            $t->string('name');
            $t->string('engine'); // postgres|mysql|mariadb|redis|mongodb
            $t->string('version');
            $t->string('environment')->default('production');
            $t->uuid('secret_id')->nullable();
            $t->string('status')->default('creating');
            $t->float('size_mb')->nullable();
            $t->integer('connections')->nullable();
            $t->timestamp('last_backup_at')->nullable();
            $t->timestamps();
        });

        // Backup
        Schema::create('backup_policies', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('target_type'); // application|volume|database|server
            $t->uuid('target_id')->nullable();
            $t->string('method')->default('restic'); // restic|dump
            $t->string('schedule')->default('0 3 * * *');
            $t->string('destination')->default('local'); // local|s3|b2|r2|gdrive
            $t->text('destination_config')->nullable(); // encrypted
            $t->integer('retention_days')->default(14);
            $t->boolean('encrypted')->default(true);
            $t->text('recovery_key')->nullable(); // encrypted, separate
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_status')->nullable();
            $t->timestamps();
        });
        Schema::create('backup_jobs', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('backup_policy_id')->nullable()->constrained('backup_policies')->nullOnDelete();
            $t->uuid('application_id')->nullable()->index();
            $t->uuid('job_id')->nullable();
            $t->uuid('server_id')->nullable();
            $t->string('target_type');
            $t->uuid('target_id')->nullable();
            $t->string('trigger')->default('manual'); // manual|schedule|pre_restore
            $t->string('status')->default('queued');
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('backup_artifacts', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('backup_job_id')->constrained('backup_jobs')->cascadeOnDelete();
            $t->uuid('application_id')->nullable()->index();
            $t->string('snapshot_id')->nullable();
            $t->string('repository');
            $t->float('size_mb')->default(0);
            $t->string('checksum')->nullable();
            $t->boolean('verified')->default(false);
            $t->boolean('encrypted')->default(true);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('restore_jobs', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('backup_artifact_id')->constrained('backup_artifacts')->cascadeOnDelete();
            $t->uuid('job_id')->nullable();
            $t->uuid('pre_restore_backup_job_id')->nullable();
            $t->string('status')->default('queued');
            $t->foreignUuid('user_id')->nullable();
            $t->timestamps();
        });

        // Operations
        Schema::create('jobs', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('type');
            $t->string('status')->default('queued');
            $t->unsignedTinyInteger('progress')->default(0);
            $t->json('stages')->nullable();
            $t->json('payload')->nullable();
            $t->json('result')->nullable();
            $t->text('error')->nullable();
            $t->string('resource_type')->nullable();
            $t->uuid('resource_id')->nullable();
            $t->foreignUuid('user_id')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['resource_type', 'resource_id']);
        });
        Schema::create('job_attempts', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('job_id')->constrained('jobs')->cascadeOnDelete();
            $t->integer('attempt');
            $t->string('status');
            $t->text('message')->nullable();
            $t->timestamps();
        });
        Schema::create('agent_commands', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->uuid('job_id')->nullable()->index();
            $t->string('type');
            $t->longText('payload'); // encrypted
            $t->string('status')->default('queued'); // queued|sent|running|succeeded|failed
            $t->unsignedTinyInteger('progress')->default(0);
            $t->text('message')->nullable();
            $t->json('output')->nullable();
            $t->string('issued_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['server_id', 'status']);
        });
        Schema::create('agent_events', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('server_id')->constrained('servers')->cascadeOnDelete();
            $t->string('type');
            $t->json('data')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('metrics', function (Blueprint $t) {
            $t->id();
            $t->uuid('organization_id')->index();
            $t->uuid('server_id');
            $t->uuid('container_id')->nullable();
            $t->float('cpu_pct')->nullable();
            $t->float('ram_pct')->nullable();
            $t->float('mem_mb')->nullable();
            $t->float('disk_pct')->nullable();
            $t->unsignedBigInteger('net_rx')->nullable();
            $t->unsignedBigInteger('net_tx')->nullable();
            $t->timestamp('recorded_at')->index();
            $t->index(['server_id', 'recorded_at']);
        });
        Schema::create('alert_rules', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('metric'); // cpu|ram|disk|heartbeat|container_unhealthy|backup_failed|ssl_expiry
            $t->string('operator')->default('>');
            $t->float('threshold')->nullable();
            $t->integer('duration_minutes')->default(0);
            $t->string('severity')->default('warning'); // info|warning|critical
            $t->string('scope_type')->default('organization'); // organization|server_group|server|application
            $t->uuid('scope_id')->nullable();
            $t->json('channel_ids')->nullable();
            $t->boolean('enabled')->default(true);
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });
        Schema::create('alerts', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('alert_rule_id')->nullable()->constrained('alert_rules')->nullOnDelete();
            $t->string('resource_type')->nullable();
            $t->uuid('resource_id')->nullable();
            $t->string('severity');
            $t->string('title');
            $t->text('message')->nullable();
            $t->string('status')->default('open'); // open|acknowledged|resolved
            $t->float('value')->nullable();
            $t->foreignUuid('acknowledged_by')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('notification_channels', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('type'); // log|email|slack|teams|telegram|webhook
            $t->text('config')->nullable(); // encrypted
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('integrations', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('provider'); // hetzner|digitalocean|vultr|linode|aws|proxmox|github|gitlab|gitea|cloudflare|stripe|oidc
            $t->text('credentials')->nullable(); // encrypted
            $t->json('config')->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('scheduled_jobs', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->string('trigger')->default('cron'); // cron|webhook|manual
            $t->string('cron')->nullable();
            $t->string('action'); // backup_policy|container_restart|image_prune|container_prune|webhook
            $t->json('params')->nullable();
            $t->string('webhook_token_hash', 64)->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_status')->nullable();
            $t->text('last_result')->nullable();
            $t->timestamp('next_run_at')->nullable();
            $t->timestamps();
        });
        Schema::create('maintenance_windows', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->uuid('server_id')->nullable();
            $t->uuid('server_group_id')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->boolean('suppress_alerts')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('terminal_sessions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('container_id')->constrained('containers')->cascadeOnDelete();
            $t->foreignUuid('user_id');
            $t->string('token_hash', 64);
            $t->string('shell_user')->default('app');
            $t->timestamp('expires_at');
            $t->timestamp('ended_at')->nullable();
            $t->string('recording_path')->nullable();
            $t->string('ip')->nullable();
            $t->timestamps();
        });

        // Security
        Schema::create('secrets', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('name');
            $t->text('value'); // encrypted
            $t->string('owner_type')->nullable();
            $t->uuid('owner_id')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->uuid('organization_id')->nullable()->index();
            $t->uuid('user_id')->nullable();
            $t->string('actor')->nullable();
            $t->string('action');
            $t->string('resource_type')->nullable();
            $t->string('resource_id')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('request_id')->nullable();
            $t->json('meta')->nullable();
            $t->string('prev_hash', 64)->nullable();
            $t->string('hash', 64);
            $t->timestamp('created_at')->index();
        });
        Schema::create('approvals', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('action');
            $t->string('resource_type')->nullable();
            $t->uuid('resource_id')->nullable();
            $t->json('payload')->nullable();
            $t->string('status')->default('pending'); // pending|approved|rejected|expired
            $t->foreignUuid('requested_by');
            $t->foreignUuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->json('result')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->uuid('user_id');
            $t->string('key');
            $t->string('method', 10);
            $t->string('path');
            $t->string('fingerprint', 64);
            $t->smallInteger('status')->nullable();
            $t->longText('body')->nullable();
            $t->timestamp('created_at')->index();
            $t->unique(['user_id', 'key']);
        });

        // Billing
        Schema::create('plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->string('name');
            $t->float('vcpu');
            $t->integer('ram_mb');
            $t->integer('storage_gb');
            $t->integer('price_cents');
            $t->string('currency')->default('EUR');
            $t->json('limits'); // apps, ram_mb, storage_gb, bandwidth_gb, backups_per_day, backup_retention_days
            $t->integer('sort')->default(0);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignUuid('plan_id')->constrained('plans');
            $t->string('status')->default('active');
            $t->string('provider_subscription_id')->nullable();
            $t->timestamp('current_period_start')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->timestamps();
        });
        Schema::create('usage_records', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('metric'); // app_hours|ram_mb_hours|storage_gb|bandwidth_gb
            $t->float('quantity');
            $t->uuid('application_id')->nullable();
            $t->date('period');
            $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('number')->unique();
            $t->date('period_start');
            $t->date('period_end');
            $t->integer('amount_cents');
            $t->string('currency')->default('EUR');
            $t->string('status')->default('paid'); // draft|open|paid|void
            $t->string('pdf_url')->nullable();
            $t->string('provider_invoice_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (array_reverse(['organizations', 'roles', 'permissions', 'role_permissions', 'memberships', 'passkeys', 'regions', 'server_groups', 'servers', 'enrollment_tokens', 'agents', 'agent_certificates', 'registries', 'containers', 'images', 'networks', 'storage_nodes', 'storage_pools', 'volumes', 'volume_mounts', 'projects', 'environments', 'applications', 'application_members', 'env_vars', 'deployments', 'releases', 'compose_projects', 'compose_revisions', 'compose_services', 'domains', 'dns_checks', 'certificates', 'routes', 'proxy_rules', 'databases', 'backup_policies', 'backup_jobs', 'backup_artifacts', 'restore_jobs', 'jobs', 'job_attempts', 'agent_commands', 'agent_events', 'metrics', 'alert_rules', 'alerts', 'notification_channels', 'integrations', 'scheduled_jobs', 'maintenance_windows', 'terminal_sessions', 'secrets', 'audit_logs', 'approvals', 'idempotency_keys', 'plans', 'subscriptions', 'usage_records', 'invoices']) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
