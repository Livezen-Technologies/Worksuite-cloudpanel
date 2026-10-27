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
