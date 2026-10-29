<?php

use App\Models\AgentEvent;
use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Services\AlertService;
use App\Services\BackupService;
use App\Services\DeploymentService;
use App\Services\DomainService;
use App\Services\MetricsService;
use App\Services\ScheduledJobService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('deployments:resume {--stale=10}', function (DeploymentService $deployments) {
    $this->info('Re-driven deployments: '.$deployments->resumeInterrupted((int) $this->option('stale')));
})->purpose('Resume or roll back interrupted deployments (SRS 8.3)');

Artisan::command('alerts:evaluate', function (AlertService $alerts) {
    $stats = $alerts->evaluateAll();
    $this->info("Alerts opened: {$stats['opened']}, resolved: {$stats['resolved']}");
})->purpose('Evaluate alert rules for every organisation (MON-003/004)');

Artisan::command('scheduled-jobs:run-due', function (ScheduledJobService $jobs) {
    $this->info('Scheduled jobs started: '.$jobs->runDue());
})->purpose('Run cron-triggered scheduled jobs that are due (AUT-001)');

Artisan::command('backups:run-due', function (BackupService $backups) {
    $count = 0;
    foreach ($backups->dueSchedules() as $policy) {
        app(Tenant::class)->as($policy->organization_id, function () use ($backups, $policy, &$count) {
            try {
                $backups->run($policy, $policy->target_type, $policy->target_id, 'schedule');
                $count++;
            } catch (\Throwable $e) {
                $policy->update(['last_status' => 'failed']);
                report($e);
            }
        });
    }
    $this->info("Backups started: {$count}");
})->purpose('Start backups whose policy schedule is due (BAK-001)');

Artisan::command('backups:prune', function (BackupService $backups) {
    $this->info('Expired artifacts removed: '.$backups->pruneExpired());
})->purpose('Apply backup retention');

Artisan::command('servers:check-heartbeats', function () {
    // Status is computed on read; this records offline transitions as agent events.
    foreach (Server::allTenants()->whereNotNull('last_heartbeat_at')->where('last_heartbeat_at', '<', now()->subSeconds(config('platform.offline_after_seconds')))->get() as $server) {
        $last = AgentEvent::allTenants()->where('server_id', $server->id)->latest('created_at')->value('type');
        if ($last !== 'disconnected') {
            AgentEvent::create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'type' => 'disconnected', 'data' => ['last_heartbeat_at' => $server->last_heartbeat_at], 'created_at' => now()]);
        }
    }
})->purpose('Record servers that went offline (no heartbeat for 90 s)');

Artisan::command('domains:check-dns', function (DomainService $domains) {
    foreach (Domain::allTenants()->where('platform_subdomain', false)->where(fn ($q) => $q->where('dns_status', '!=', 'verified')->orWhere('https_status', '!=', 'active'))->get() as $domain) {
        app(Tenant::class)->as($domain->organization_id, fn () => rescue(fn () => $domains->verify($domain)));
    }
})->purpose('Re-check pending DNS every 2 minutes (UDOM-003)');

Artisan::command('apps:purge', function () {
    $purged = 0;
    foreach (Application::allTenants()->onlyTrashed()->where('purge_after', '<', now())->get() as $app) {
        $app->forceDelete();
        $purged++;
    }
    $this->info("Purged apps: {$purged}");
})->purpose('Purge deleted apps after the 7-day retention window');

Artisan::command('metrics:prune', function (MetricsService $metrics) {
    $this->info('Raw metric rows removed: '.$metrics->pruneRaw(15));
})->purpose('Metrics retention: 15 days raw');

Artisan::command('audit:prune', function () {
    foreach (\App\Models\Organization::all() as $org) {
        // Retention is enforced by an explicit, audited purge of the oldest rows only.
        $days = (int) $org->setting('general.audit_retention_days', 365);
        \Illuminate\Support\Facades\DB::table('audit_logs')->where('organization_id', $org->id)->where('created_at', '<', now()->subDays($days))->delete();
    }
})->purpose('Apply audit log retention (default 365 days)');

Artisan::command('backups:restore-tests {--all : test the latest artifact of every policy now}', function (BackupService $backups) {
    $artifacts = $this->option('all')
        ? \App\Models\BackupPolicy::allTenants()->get()->map(fn ($p) => \App\Models\BackupArtifact::allTenants()->where('backup_policy_id', $p->id)->whereNotNull('snapshot_id')->latest()->first())->filter()
        : $backups->dueRestoreTests();
    $count = 0;
    foreach ($artifacts as $artifact) {
        app(Tenant::class)->as($artifact->organization_id, function () use ($backups, $artifact, &$count) {
            rescue(function () use ($backups, $artifact, &$count) {
                $backups->restoreTest($artifact);
                $count++;
            });
        });
    }
    $this->info("Restore tests started: {$count}");
})->purpose('Scheduled restore tests of the latest snapshot per policy (BAK-005)');

Artisan::command('backups:recovery-key {policy : backup policy id} {--key= : BACKUP_RECOVERY_KEY if not configured}', function () {
    $policy = \App\Models\BackupPolicy::allTenants()->findOrFail($this->argument('policy'));
    if (! $policy->recovery_key || ! str_starts_with($policy->recovery_key, 'escrow:')) {
        $this->error('No escrowed recovery key yet (it is created on the first backup run).');

        return 1;
    }
    $this->line(\App\Support\BackupDestination::unescrow($policy->recovery_key, $this->option('key') ?: null));
    $this->warn('restic repository: '.(new \App\Support\BackupDestination($policy, $policy->organization_id))->repository());
})->purpose('Print the escrowed restic password of a policy (BAK-004 recovery; run on the panel host only)');

Artisan::command('billing:meter', function (\App\Services\InvoiceService $invoices) {
    $this->info('Usage records written: '.$invoices->meter());
})->purpose('Record one app-hour per running customer app (usage_records)');

Artisan::command('billing:invoice {--month= : YYYY-MM, default previous month} {--org= : organisation id}', function (\App\Services\InvoiceService $invoices) {
    $month = $this->option('month') ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $this->option('month').'-01') : now()->subMonthNoOverflow();
    if ($org = $this->option('org')) {
        $invoice = app(Tenant::class)->as($org, fn () => $invoices->generate($month));
        $this->info($invoice ? "Invoice {$invoice->number}: {$invoice->amount_cents} {$invoice->currency} ({$invoice->status}) {$invoice->pdf_path}" : 'Nothing to invoice.');

        return;
    }
    $this->info('Invoices generated: '.$invoices->generateAll($month));
})->purpose('Roll usage_records into monthly invoices with PDFs (UBIL-004)');

Artisan::command('proxy:push {--force}', function (\App\Services\TraefikConfigService $traefik) {
    $this->info('proxy.config commands queued: '.$traefik->push((bool) $this->option('force')));
})->purpose('Push Traefik dynamic config to ingress servers (CERT_DRIVER=traefik)');

Artisan::command('proxy:sync-certs', function (\App\Services\TraefikConfigService $traefik) {
    $this->info('proxy.certs commands queued: '.($traefik::enabled() ? $traefik->syncCertificates() : 0));
})->purpose('Read certificate expiry from Traefik acme.json on ingress servers (DOM-002)');

Artisan::command('uptime:check', function (\App\Services\UptimeService $uptime) {
    $r = $uptime->checkAll();
    $i = $uptime->checkInfrastructure();
    $this->info("sites: checked {$r['checked']}, down {$r['down']}, recovered {$r['recovered']}; "
        ."infrastructure: opened {$i['opened']}, closed {$i['closed']}");
})->purpose('Fetch every verified domain and open or close uptime incidents');

Schedule::command('uptime:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('scheduled-jobs:run-due')->everyMinute()->withoutOverlapping();
Schedule::command('backups:run-due')->everyMinute()->withoutOverlapping();
Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping();
Schedule::command('servers:check-heartbeats')->everyMinute();
Schedule::command('deployments:resume')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('domains:check-dns')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('backups:prune')->hourly();
Schedule::command('apps:purge')->daily();
Schedule::command('metrics:prune')->daily();
Schedule::command('audit:prune')->daily();

Artisan::command('agent:publish {--from= : directory holding install.sh and dockermgr-agent-linux-* binaries}', function () {
    $target = config('platform.agent_binary_dir');
    @mkdir($target, 0755, true);
    $from = $this->option('from') ?: base_path('../agent/dist');
    $copied = [];
    $script = config('platform.agent_install_script');
    if (is_file($script) && copy($script, "{$target}/install.sh")) {
        $copied[] = 'install.sh';
    }
    foreach (glob(rtrim($from, '/').'/dockermgr-agent-linux-*') ?: [] as $binary) {
        copy($binary, $target.'/'.basename($binary));
        $copied[] = basename($binary).' sha256='.hash_file('sha256', $binary);
    }
    $this->info($copied ? 'Published: '.implode(', ', $copied) : 'Nothing found to publish.');
})->purpose('Copy the agent installer and binaries into storage/app/agent for /agent/v1/download');

Schedule::command('backups:restore-tests')->everyMinute()->withoutOverlapping();
Schedule::command('billing:meter')->hourly();
Schedule::command('billing:invoice')->monthlyOn(1, '02:15');
Schedule::command('proxy:push')->everyTenMinutes()->withoutOverlapping();
Schedule::command('proxy:sync-certs')->everySixHours()->withoutOverlapping();
