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

Artisan::command('antivirus:scan-all', function (\App\Services\AntivirusService $antivirus) {
    $this->info('Queued '.$antivirus->scanAll().' scan(s).');
})->purpose('AV-3: scan every local volume on every online server');

Artisan::command('antivirus:scan-due', function (\App\Services\AntivirusService $antivirus) {
    $this->info('Queued '.$antivirus->scanDue().' scheduled scan(s).');
})->purpose('AV-3: start the full scans whose scheduled time has come');

Artisan::command('integrity:check-all', function (\App\Services\IntegrityService $integrity) {
    $this->info('Queued '.$integrity->checkAll().' integrity check(s).');
})->purpose('AV-10: compare every baselined volume with its baseline');

Artisan::command('images:scan-stale', function (\App\Services\ScanService $scans) {
    $this->info('Queued '.$scans->scanStale().' image scan(s).');
})->purpose('AV-11: rescan images not scanned in the last day');

Artisan::command('antivirus:realtime-events', function (\App\Services\ServerSecurityService $security) {
    $this->info('Collecting real-time detections from '.$security->collectEvents().' server(s).');
})->purpose('AV-2: turn clamonacc detections into threats');

Artisan::command('antivirus:reapply', function (\App\Services\ServerSecurityService $security) {
    $this->info('Re-applied server security on '.$security->reapplyAll().' server(s).');
})->purpose('AV-2 / AV-12: push real-time scanning and outbound limits again (they do not survive a reboot)');

Artisan::command('wordpress:detect', function (\App\Services\WordPressService $wp) {
    $this->info('Inventory queued for '.$wp->detect().' WordPress site(s).');
})->purpose('WP-001: find WordPress apps and refresh their versions, plugins, themes and vulnerabilities');

Artisan::command('mail:health', function (\App\Services\MailService $mail) {
    foreach (\App\Models\MailServer::allTenants()->where('status', 'running')->get() as $m) {
        $mail->checkHealth($m);
        $mail->refreshCerts($m); // MAIL-005: pick up renewed certificates
    }
})->purpose('MAIL-010: reverse DNS and blocklist checks for every mail server');

Artisan::command('wordpress:cron', function (\App\Services\WordPressService $wp) {
    $this->info('Ran WP-Cron for '.$wp->runCron().' site(s).');
})->purpose('WP-003: run due WP-Cron events for sites using platform-run cron');

Artisan::command('firewall:refresh-blocked', function (\App\Services\BlockedIpService $blocked) {
    $this->info('Asked '.$blocked->refresh().' ingress server(s) for their current bans');
})->purpose('Refresh the Blocked IPs list from CrowdSec on every ingress');

Artisan::command('firewall:sync-events', function (\App\Services\FirewallEventService $events) {
    $this->info('Queued crowdsec.alerts on '.$events->sync().' ingress server(s).');
})->purpose('FW-001: pull recent CrowdSec alerts into the firewall event log');

Artisan::command('firewall:expire-ips', function (\App\Services\TraefikConfigService $traefik) {
    $n = \App\Models\FirewallIpList::allTenants()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
    $n && $traefik->push();
    $this->info("Removed {$n} expired firewall IP entr".($n === 1 ? 'y' : 'ies').'.');
})->purpose('FW-011: drop expired allow/block list entries and re-render the edge');

Artisan::command('maintenance:apply-schedules', function (\App\Services\MaintenanceScheduleService $schedules) {
    [$locked, $unlocked] = $schedules->run();
    $this->info("Scheduled maintenance: {$locked} locked, {$unlocked} unlocked.");
})->purpose('Lock apps whose scheduled maintenance window has started and unlock the ones it locked once it ends');

Artisan::command('scaling:evaluate', function (\App\Services\ScalingService $scaling) {
    $this->info('Apps rescaled: '.$scaling->evaluate());
})->purpose('SCL-2: autoscale on CPU and follow scaling schedules');

Artisan::command('cleanup:evaluate', function (\App\Services\CleanupService $cleanup) {
    $this->info('Cleanups started: '.$cleanup->evaluate());
})->purpose('CLN-2: clean servers whose disk use crossed the policy threshold');

Artisan::command('environments:sleep', function (\App\Services\EnvironmentService $envs) {
    $this->info('Apps put to sleep: '.$envs->sleepIdle());
})->purpose('ENV-7: stop idle apps in environments with a sleep window');

Artisan::command('apps:check-git-updates', function (\App\Services\GitUpdateService $updates) {
    $s = $updates->checkAll();
    $this->info("Checked {$s['checked']}, updates available {$s['available']}, rebuilt {$s['deployed']}.");
})->purpose('Compare Git-built apps with the head of their branch and rebuild those set to update automatically');

Artisan::command('templates:sync', function (\App\Services\TemplateService $templates) {
    $s = $templates->sync();
    $this->info("bundled {$s['bundled']}, remote {$s['remote']}, invalid ".count($s['invalid']).(isset($s['remote_error']) ? "; remote error: {$s['remote_error']}" : ''));
})->purpose('TPL-4: load template manifests (bundled and TEMPLATES_REMOTE_URL)');

Artisan::command('templates:validate', function (\App\Services\TemplateService $templates) {
    $failed = 0;
    foreach ($templates->bundled() as $m) {
        $r = $templates->lint($m);
        foreach ($r['errors'] as $e) {
            $this->error(($m['id'] ?? '?').": {$e}");
        }
        foreach ($r['warnings'] as $w) {
            $this->warn(($m['id'] ?? '?').": {$w}");
        }
        $failed += $r['errors'] ? 1 : 0;
    }
    $this->info(count($templates->bundled())." templates checked, {$failed} failed");

    return $failed ? 1 : 0;
})->purpose('TPL-6: render and check every bundled template');

// CRON: refresh each server's own runner schedule (apps can move servers; a missed sync heals here).
Artisan::command('cron:sync-runners', function (\App\Services\ScheduledJobService $cron) {
    $n = 0;
    foreach (\App\Models\Server::allTenants()->get() as $server) {
        if ($server->isOnline()) {
            app(\App\Support\Tenant::class)->as($server->organization_id, fn () => $cron->syncRunner($server));
            $n++;
        }
    }
    $this->info("Runner schedules sent: {$n}");
})->purpose('Send each online server the schedule its agent runs while the panel is unreachable');

Artisan::command('tunnels:sync', function (\App\Services\CloudflareTunnelService $tunnels) {
    $this->info('Tunnels synced: '.$tunnels->syncAll());
})->purpose('CFT-3..5: refresh Cloudflare Tunnel hostnames, DNS and health');

Artisan::command('services:check', function (\App\Services\SystemServiceService $svc) {
    $this->info('System services checked on '.$svc->checkAll().' servers');
})->purpose('SVC-4: refresh watched systemd services and alert when one is down');

Artisan::command('mesh:status', function (\App\Services\MeshService $mesh) {
    $this->info('Mesh status requested from '.$mesh->refreshStatus().' servers');
})->purpose('PNET-5: WireGuard handshakes and traffic per server');

Artisan::command('mail:watch', function (\App\Services\MailGuardService $guard) {
    $this->info('Mail servers checked: '.$guard->watchAll());
})->purpose('MCL: delivery log, and holds for mailboxes or domains sending too much or bouncing');

Artisan::command('registry:gc', function (\App\Services\RegistryGcService $gc) {
    $this->info('Old images deleted: '.$gc->collectAll());
})->purpose('BLD: delete pushed images older than the last BUILD_REGISTRY_KEEP releases of each app');

Artisan::command('edge:failover', function (\App\Services\EdgeFailoverService $edge) {
    $r = $edge->check();
    $this->info('Online edge servers: '.implode(', ', $r['online']).'; changes: '.(implode(', ', $r['changes']) ?: 'none'));
})->purpose('SCL-8: DNS failover between ingress servers');

Artisan::command('edge:tls-renew', function (\App\Services\EdgeTlsService $tls) {
    $r = $tls->renewDue();
    $this->info('Edge TLS certificates renewed: '.(implode(', ', $r) ?: 'none'));
})->purpose('XNODE-3b: renew app-node edge certificates within EDGE_BACKEND_TLS_RENEW_DAYS of expiry');

Artisan::command('edge:tls-sync {--force}', function (\App\Services\EdgeTlsService $tls) {
    $this->info('ws-edge-tls nodes updated: '.$tls->syncAll((bool) $this->option('force')));
})->purpose('XNODE-3b: write/refresh the ws-edge-tls terminators on the app nodes');

Artisan::command('edge:watchdog', function (\App\Services\EdgeWatchdogService $w) {
    $r = $w->run();
    $this->info(isset($r['skipped']) ? 'Skipped: '.$r['skipped'] : 'Edges probed: '.count($r['probed']).'; changes: '.(implode(', ', $r['changes']) ?: 'none'));
})->purpose('XNODE-4: probe the enterprise edges and drop/restore their Cloudflare A records');

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

Artisan::command('backups:record-external {policy : Policy id (method "external"), or "-" with --name} {--name= : Find or create the external policy by name} {--org= : Organisation for --name} {--server= : Server the backed-up site lives on, for --name} {--schedule=15 2 * * *} {--retention=30} {--status=succeeded} {--error=} {--started=} {--manifest= : TSV of name, bytes, sha256, location} {--log= : File with the run log to keep with the job}', function (BackupService $backups) {
    if ($this->argument('policy') === '-') {
        $org = (string) $this->option('org');
        $policy = app(Tenant::class)->as($org, fn () => \App\Models\BackupPolicy::firstOrCreate(
            ['name' => (string) $this->option('name')],
            ['organization_id' => $org, 'target_type' => 'server', 'target_id' => $this->option('server'), 'method' => 'external', 'destination' => 'sftp',
                'schedule' => (string) $this->option('schedule'), 'retention_days' => (int) $this->option('retention'), 'encrypted' => false, 'enabled' => true],
        ));
    } else {
        $policy = \App\Models\BackupPolicy::allTenants()->findOrFail($this->argument('policy'));
    }
    $items = [];
    if ($path = $this->option('manifest')) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$name, $bytes, $sha, $location] = array_pad(explode("\t", $line), 4, '');
            $items[] = ['name' => $name, 'bytes' => (int) $bytes, 'sha256' => $sha, 'location' => $location];
        }
    }
    $started = $this->option('started') ? \Illuminate\Support\Carbon::parse($this->option('started')) : now();
    $job = app(Tenant::class)->as($policy->organization_id, fn () => $backups->recordExternal($policy, (string) $this->option('status'), $this->option('error') ?: null, $started, $items, null, $this->option('log') && is_file($this->option('log')) ? (string) file_get_contents($this->option('log')) : null));
    $this->info("Recorded {$job->status} run {$job->id} with ".count($items).' artifact(s)');
})->purpose('Record a run of an external backup policy (a job outside the panel that reports here)');

Artisan::command('backups:pool-check', function (\App\Services\BackupPoolService $pool) {
    foreach (\App\Models\BackupStorageServer::allTenants()->where('enabled', true)->get() as $s) {
        $s = app(Tenant::class)->as($s->organization_id, fn () => $pool->check($s));
        $this->line("{$s->name} ({$s->host}): {$s->status}".($s->free_bytes !== null ? ', '.round($s->free_bytes / 1073741824).' GB free' : '').($s->message ? " - {$s->message}" : ''));
    }
})->purpose('Check every backup storage server: reachability and free space');

Artisan::command('alerts:seed-defaults', function (AlertService $alerts) {
    foreach (\App\Models\Organization::all() as $org) {
        app(Tenant::class)->as($org->id, fn () => $alerts->seedDefaults($org));
    }
    $this->info('Default alert rules ensured for every organisation');
})->purpose('Add any missing default alert rules (e.g. Backups overdue) to existing organisations');

Artisan::command('backups:pool-target {dir : Private directory to write the key and known_hosts into} {--org=}', function (\App\Services\BackupPoolService $pool) {
    $s = $pool->pick($this->option('org') ?: null);
    foreach ($pool->rcloneEnv($s, $this->argument('dir')) + ['POOL_SERVER_ID' => $s->id, 'POOL_SERVER_NAME' => $s->name, 'POOL_PATH' => trim($s->path, '/')] as $k => $v) {
        $this->line('export '.$k.'='.escapeshellarg($v));
    }
})->purpose('Print the rclone environment for the pool server with the most free space (used by host backup jobs)');

Artisan::command('firewall:open {domain? : Domain name; all domains when omitted}', function () {
    // Break-glass: every (or one) domain back to "open" IP access, then the proxy is re-pushed.
    // Block-list entries stay; delete them in the panel or with tinker if they are the problem.
    $q = \App\Models\Domain::allTenants()->where('ip_access', 'allowlist');
    if ($name = $this->argument('domain')) {
        $q->where('name', $name);
    }
    $n = $q->update(['ip_access' => 'open']);
    app(\App\Services\AuditLogger::class)->log('firewall.emergency_open', 'domains', null, ['domain' => $name, 'count' => $n], null, null, 'console');
    app(\App\Services\TraefikConfigService::class)->push();
    $this->info("{$n} domain(s) opened to all addresses; proxy configuration pushed.");
})->purpose('Emergency: switch allow-list-only domains back to open access');

Artisan::command('projects:link-containers', function (\App\Services\ProjectContainerService $svc) {
    $this->info($svc->sync().' container(s) regrouped under projects');
})->purpose("Group each site's containers (database, Redis, workers) under its app's project, by Compose label");

Artisan::command('backups:prune', function (BackupService $backups) {
    $this->info('Expired artifacts removed: '.$backups->pruneExpired());
})->purpose('Apply backup retention');

Artisan::command('servers:check-heartbeats', function () {
    // Status is computed on read; this records offline transitions as agent events.
    foreach (Server::allTenants()->whereNotNull('last_heartbeat_at')->where('last_heartbeat_at', '<', now()->subSeconds(config('platform.offline_after_seconds')))->get() as $server) {
        $last = AgentEvent::allTenants()->where('server_id', $server->id)->latest('created_at')->value('type');
        if ($last !== 'disconnected') {
            AgentEvent::create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'type' => 'disconnected', 'data' => ['last_heartbeat_at' => $server->last_heartbeat_at], 'created_at' => now()]);
            app(\App\Services\EmailTemplateService::class)->notifyOrganization($server->organization_id, 'server_unreachable', [
                'server' => $server->name, 'since' => $server->last_heartbeat_at->toDayDateTimeString().' UTC',
            ]);
        }
    }
})->purpose('Record servers that went offline (no heartbeat for 90 s)');

Artisan::command('domains:check-dns', function (DomainService $domains, \App\Services\DomainStateNotifier $notifier) {
    // Pending domains every run; live project domains every 30 minutes too, so one that breaks
    // again is noticed (PDNS-2). Each change of state notifies the project once.
    $due = Domain::allTenants()->where('platform_subdomain', false)->where(fn ($q) => $q->where('dns_status', '!=', 'verified')->orWhere('https_status', '!=', 'active')
        ->orWhere(fn ($q) => $q->whereNotNull('project_id')->where(fn ($q) => $q->whereNull('dns_checked_at')->orWhere('dns_checked_at', '<', now()->subMinutes(30)))));
    foreach ($due->get() as $domain) {
        app(Tenant::class)->as($domain->organization_id, function () use ($domain, $domains, $notifier) {
            $notifier->baseline($domain);
            rescue(fn () => $domains->verify($domain));
            rescue(fn () => $notifier->afterCheck($domain));
        });
    }
})->purpose('Re-check pending DNS every 2 minutes (UDOM-003) and notify project domain state changes (PDNS-2)');

Artisan::command('domains:acme-delegate {--dry-run : list what would change}', function () {
    // ACME-DLG: Enterprise custom domains added before delegation existed are still on HTTP-01,
    // which fails on the edges. Moves those that have never had a certificate to DNS-01 by
    // delegation and prints the CNAME each one needs. Idempotent; never touches a domain that
    // has an active certificate.
    $due = Domain::allTenants()->with('application')->where('challenge', 'http-01')->where('platform_subdomain', false)->get()
        ->filter(fn (Domain $d) => $d->https_status !== 'active' && \App\Services\AcmeDelegation::eligible($d)
            && ! \App\Models\Certificate::allTenants()->where('domain_id', $d->id)->where('status', 'active')->exists());
    if ($due->isEmpty()) {
        $this->info('Nothing to switch.');

        return 0;
    }
    foreach ($due as $domain) {
        $name = \App\Services\AcmeDelegation::recordName($domain);
        $target = \App\Services\AcmeDelegation::target($domain);
        if (! $this->option('dry-run')) {
            app(Tenant::class)->as($domain->organization_id, function () use ($domain, $name, $target) {
                $domain->update(['challenge' => 'dns-01']);
                app(\App\Services\AuditLogger::class)->log('domain.acme_delegated', 'domains', $domain->id,
                    ['domain' => $domain->name, 'record' => "{$name} CNAME {$target}"], $domain->organization_id, null, 'system');
            });
        }
        $this->line(($this->option('dry-run') ? '[dry run] ' : '').$domain->name.": http-01 → dns-01. Customer adds: {$name} CNAME {$target}.");
    }

    return 0;
})->purpose('Move Enterprise custom domains on the edges without a certificate to DNS-01 by CNAME delegation (ACME-DLG)');

Artisan::command('dns:refresh-psl', function () {
    // PDNS-2: fetch the current Public Suffix List into storage; the bundled copy stays as the fallback.
    $body = \Illuminate\Support\Facades\Http::timeout(30)->get('https://publicsuffix.org/list/public_suffix_list.dat')->throw()->body();
    if (strlen($body) < 100000 || ! str_contains($body, '===BEGIN ICANN DOMAINS===')) {
        $this->error('The downloaded list does not look like the Public Suffix List; kept the current one.');

        return 1;
    }
    @mkdir(storage_path('app/psl'), 0755, true);
    file_put_contents(storage_path('app/psl/public_suffix_list.dat'), $body);
    \App\Support\DomainName::forget();
    $this->info('Public Suffix List updated ('.strlen($body).' bytes).');
})->purpose('Refresh the Public Suffix List used for apex/subdomain detection (PDNS-2)');

Artisan::command('networks:reconcile', function (\App\Services\NetworkService $networks) {
    // PRJ-NET: make sure every environment that has running apps owns a Docker
    // network on each server those apps sit on. Deployments only ever *join* a
    // network that already exists, so without this the first deployment after
    // a change would have to go without one.
    $made = 0;
    $skipped = [];
    $apps = \App\Models\Application::allTenants()->whereNotNull('environment_id')->whereNotNull('server_id')
        ->whereNull('deleted_at')->get(['organization_id', 'environment_id', 'server_id']);
    foreach ($apps->groupBy(fn ($a) => $a->environment_id.'|'.$a->server_id) as $key => $pair) {
        $app = $pair->first();
        app(Tenant::class)->as($app->organization_id, function () use ($app, $key, $networks, &$made, &$skipped) {
            $environment = \App\Models\Environment::find($app->environment_id);
            // A server can belong to one organisation and host another's apps,
            // so it must be resolved outside the tenant scope: the scoped
            // lookup returned null and skipped those environments in silence.
            $server = \App\Models\Server::allTenants()->find($app->server_id);
            if (! $environment || ! $server) {
                $skipped[] = $key;

                return;
            }
            if ($networks->forEnvironment($environment, $server)->wasRecentlyCreated) {
                $made++;
            }
        });
    }
    // Networks that already exist still need the ingress on them: the three on
    // the live panel were created before the attach existed, and an attach that
    // failed once must be retried rather than waiting for a new network.
    $attached = 0;
    foreach (\App\Models\Network::allTenants()->whereNotNull('environment_id')->where('status', 'active')->get() as $network) {
        app(Tenant::class)->as($network->organization_id, function () use ($network, $networks, &$attached) {
            $attached += $networks->attachIngress($network);
        });
    }
    $this->info("Environment networks requested: {$made}; ingress attachments dispatched: {$attached}");
    if ($skipped) {
        // Never silent: an environment or server that cannot be resolved means
        // an environment stays without a network, and nothing else would say so.
        $this->warn('Could not resolve environment or server for: '.implode(', ', $skipped));
    }
})->purpose('Create the Docker network each environment needs on each of its servers');

Artisan::command('certificates:renew', function (DomainService $domains) {
    $r = $domains->renewExpiring();
    $this->info("Certificates renewed: {$r['renewed']}, failed: {$r['failed']}");
})->purpose('Renew certificates that expire within 21 days');

Artisan::command('migrations:cleanup', function (\App\Services\MigrationService $migrations) {
    $this->info('Migration archives removed: '.$migrations->cleanupArchives());
})->purpose('Free disk space: remove downloaded backup archives of finished or failed migrations');

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

Artisan::command('billing:dunning', function (\App\Services\InvoiceService $invoices) {
    [$retried, $paid] = $invoices->retryDue();
    $this->info("Failed invoices retried: {$retried}, now paid: {$paid}");
})->purpose('Retry failed invoice payments (3 attempts, 3 days apart)');

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

Artisan::command('finance:demo {--force : run even though customers already exist}', function () {
    // Demo rows mixed into real books are worse than no demo rows, so this
    // stops unless the panel is empty or the operator insists.
    $customers = \App\Models\Organization::count();
    if ($customers > 1 && ! $this->option('force')) {
        $this->error("This panel already has {$customers} organisations. Re-run with --force if you are sure.");

        return 1;
    }
    $this->call('db:seed', ['--class' => \Database\Seeders\FinanceDemoSeeder::class, '--force' => true]);

    return 0;
})->purpose('Fill the finance screens with realistic demonstration data (FIN-11)');

Artisan::command('proxy:push {--force}', function (\App\Services\TraefikConfigService $traefik) {
    $this->info('proxy.config commands queued: '.$traefik->push((bool) $this->option('force')));
})->purpose('Push Traefik dynamic config to ingress servers (CERT_DRIVER=traefik)');

Artisan::command('proxy:sync-certs', function (\App\Services\TraefikConfigService $traefik) {
    $this->info('proxy.certs commands queued: '.($traefik::enabled() ? $traefik->syncCertificates() : 0));
})->purpose('Read certificate expiry from Traefik acme.json on ingress servers (DOM-002)');

Artisan::command('proxy:prune-certs {--dry-run : list what would be deleted and change nothing} {--keep=10 : renewals of history to keep per domain} {--domain= : only this domain name}', function (\App\Services\CertificateHistoryPruner $pruner) {
    $dry = (bool) $this->option('dry-run');
    $rows = $pruner->prune($dry, (int) $this->option('keep'), $this->option('domain') ?: null);
    foreach ($rows as $r) {
        $this->line(sprintf('%s: %d rows, keep %d, %s %d', $r['domain'], $r['rows'], $r['keep'], $dry ? 'would delete' : 'deleted', $r['delete']));
    }
    $this->info(sprintf('%s %d superseded certificate rows across %d domains.', $dry ? 'Dry run: would delete' : 'Deleted', array_sum(array_column($rows, 'delete')), count($rows)));
})->purpose('Delete superseded duplicate certificate rows, keeping one per renewal (idempotent)');

Artisan::command('uptime:check', function (\App\Services\UptimeService $uptime) {
    $r = $uptime->checkAll();
    $i = $uptime->checkInfrastructure();
    $this->info("sites: checked {$r['checked']}, down {$r['down']}, recovered {$r['recovered']}; "
        ."infrastructure: opened {$i['opened']}, closed {$i['closed']}");
})->purpose('Fetch every verified domain and open or close uptime incidents');

Artisan::command('monitoring:follow-up', function (\App\Services\UptimeService $uptime) {
    $f = $uptime->followUpOpenIncidents();
    $r = $uptime->autoRestartUnhealthy();
    $this->info("re-notified {$f['renotified']}, escalated {$f['escalated']}; "
        ."restarted {$r['restarted']}, rate-limited {$r['skipped']}");
})->purpose('Re-notify and escalate open incidents, and restart unhealthy containers (MON-00x)');

Artisan::command('monitoring:prune', function () {
    $days = (int) config('platform.default_settings.monitoring.uptime_retention_days');
    $n = \App\Models\UptimeCheck::allTenants()->where('checked_at', '<', now()->subDays($days))->delete();
    $this->info("uptime_checks rows pruned: {$n}");
})->purpose('Drop uptime checks past the retention window');

Artisan::command('customers:recompute-groups', function (\App\Services\CustomerGroupService $groups) {
    foreach ($groups->recomputeAll() as $slug => $result) {
        $this->line("{$slug}: {$result['matched']} matched (+{$result['added']} / -{$result['removed']})");
    }
})->purpose('CUST-5: refill every dynamic customer group from its rules');

Schedule::command('uptime:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('apps:check-git-updates')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('monitoring:follow-up')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('monitoring:prune')->daily();
Schedule::command('firewall:sync-events')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('firewall:refresh-blocked')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('firewall:expire-ips')->everyMinute()->withoutOverlapping();
Schedule::command('maintenance:apply-schedules')->everyMinute()->withoutOverlapping();
Schedule::command('scaling:evaluate')->everyMinute()->withoutOverlapping();
Schedule::command('cleanup:evaluate')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('environments:sleep')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('templates:sync')->daily();
// Membership drives pricing, so it is refreshed before the nightly billing run.
Schedule::command('customers:recompute-groups')->dailyAt('02:40')->withoutOverlapping();
Schedule::command('registry:gc')->dailyAt('03:30');
Schedule::command('cron:sync-runners')->hourly()->withoutOverlapping();
Schedule::command('tunnels:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mesh:status')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('services:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mail:watch')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('edge:failover')->everyMinute()->withoutOverlapping();
Schedule::command('edge:watchdog')->everyMinute()->withoutOverlapping();
Schedule::command('edge:tls-renew')->dailyAt('03:40')->withoutOverlapping();
// AV-3: full scans on each organisation's schedule (Settings → Antivirus; default weekly, Sunday 02:00).
Schedule::command('antivirus:scan-due')->everyFifteenMinutes()->withoutOverlapping();
// AV-10 daily integrity check; AV-11 nightly image rescans (new CVEs appear for old images).
Schedule::command('wordpress:detect')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('mail:health')->dailyAt('05:20')->withoutOverlapping();
Schedule::command('wordpress:cron')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('antivirus:realtime-events')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('antivirus:reapply')->hourly()->withoutOverlapping();
Schedule::command('integrity:check-all')->dailyAt('03:40')->withoutOverlapping();
Schedule::command('images:scan-stale')->dailyAt('01:20')->withoutOverlapping();
// FM-002/003: transfer folders left by finished or abandoned uploads and downloads.
Schedule::call(function () {
    $disk = \Illuminate\Support\Facades\Storage::disk('local');
    $cutoff = now()->subHours(config('files.download_ttl_hours'))->getTimestamp();
    foreach ($disk->directories(\App\Services\FileService::TRANSFER_DIR) as $dir) {
        $times = array_map(fn ($f) => $disk->lastModified($f), $disk->allFiles($dir));
        if (! $times || max($times) < $cutoff) {
            $disk->deleteDirectory($dir);
        }
    }
})->hourly()->name('files:prune-transfers');
// FM File Sharing: ended links lose their stored copy.
Schedule::call(fn () => app(\App\Services\FileShareService::class)->prune())->hourly()->name('files:prune-shares');
Schedule::command('scheduled-jobs:run-due')->everyMinute()->withoutOverlapping();
Schedule::command('backups:run-due')->everyMinute()->withoutOverlapping();
Schedule::command('alerts:evaluate')->everyMinute()->withoutOverlapping();
Schedule::command('servers:check-heartbeats')->everyMinute();
Schedule::command('deployments:resume')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('certificates:renew')->dailyAt('02:40')->withoutOverlapping();
Schedule::command('migrations:cleanup')->dailyAt('04:40')->withoutOverlapping();
Schedule::command('domains:check-dns')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('dns:refresh-psl')->monthly()->withoutOverlapping();
Schedule::command('networks:reconcile')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('backups:pool-check')->hourly()->withoutOverlapping();
Schedule::command('projects:link-containers')->everyFiveMinutes()->withoutOverlapping();
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
Schedule::command('billing:dunning')->dailyAt('09:10')->withoutOverlapping();
Schedule::command('billing:invoice')->monthlyOn(1, '02:15');
Schedule::command('proxy:push')->everyTenMinutes()->withoutOverlapping();
Schedule::command('proxy:sync-certs')->everySixHours()->withoutOverlapping();

/* ── Partner provisioning (docs/PARTNER_API.md) ── */
Artisan::command('partner:client {name} {--webhook= : the partner\'s webhook URL} {--scopes=deployments:read,deployments:write,deployments:operate,deployments:lifecycle} {--rotate : issue a new secret for an existing client}', function () {
    $name = (string) $this->argument('name');
    $scopes = array_values(array_intersect(explode(',', (string) $this->option('scopes')), config('partner.scopes')));
    $client = \App\Models\PartnerClient::where('name', $name)->first();
    if ($client && ! $this->option('rotate')) {
        $this->error("A partner client named {$name} exists; pass --rotate to issue new secrets.");

        return 1;
    }
    $secret = \Illuminate\Support\Str::random(64);
    $webhookSecret = \Illuminate\Support\Str::random(64);
    $attrs = ['secret' => $secret, 'webhook_secret' => $webhookSecret, 'scopes' => $scopes, 'active' => true];
    if ($this->option('webhook')) {
        $attrs['webhook_url'] = $this->option('webhook');
    }
    $client = $client ? tap($client)->update($attrs) : \App\Models\PartnerClient::create($attrs + ['name' => $name, 'key_id' => 'lzp_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(24))]);
    app(\App\Services\AuditLogger::class)->log('partner.client.'.($this->option('rotate') ? 'rotate' : 'create'), 'PartnerClient', $client->id, ['name' => $name, 'scopes' => $scopes], null, null, 'cli');
    $this->info('Shown once — put these in WorkSuite Cloud\'s api/.env:');
    $this->line("LIVEZEN_KEY_ID={$client->key_id}");
    $this->line("LIVEZEN_SECRET={$secret}");
    $this->line("LIVEZEN_WEBHOOK_SECRET={$webhookSecret}");
})->purpose('Create (or rotate) a partner client credential for server-to-server provisioning');

Artisan::command('partner:sync', function (\App\Services\PartnerProvisioningService $partners) {
    $this->info('Partner status events queued: '.$partners->sync());
})->purpose('Refresh partner deployments and queue a webhook for each visible change');

Artisan::command('partner:webhooks', function (\App\Services\PartnerWebhookService $hooks) {
    $s = $hooks->deliverDue();
    $this->info("Webhooks delivered {$s['delivered']}, retrying {$s['retrying']}, failed {$s['failed']}");
})->purpose('Deliver due partner webhooks');

Schedule::command('partner:sync')->everyMinute()->withoutOverlapping();
Schedule::command('partner:webhooks')->everyMinute()->withoutOverlapping();

// Enterprise provisioning (docs/ENTERPRISE_API.md).
Artisan::command('enterprise:sync', function (\App\Services\EnterpriseProvisioningService $svc) {
    $this->info('Advanced '.$svc->sync().' enterprise deployment(s).');
})->purpose('Advance enterprise deployments (provisioning steps, domains, status)');
Artisan::command('enterprise:webhooks', function (\App\Services\EnterpriseWebhookService $hooks) {
    $this->info(json_encode($hooks->deliverDue()));
})->purpose('Deliver signed enterprise status webhooks to Cloud Admin');
Artisan::command('enterprise:service-account {--email=cloud-admin-enterprise@service.invalid} {--org= : staff organisation id (default config enterprise.organization_id)} {--days=365}', function () {
    $orgId = $this->option('org') ?: config('enterprise.organization_id');
    $org = \App\Models\Organization::query()->withoutGlobalScopes()->find($orgId);
    if (! $org) {
        $this->error("Organisation {$orgId} not found.");

        return 1;
    }
    $user = \App\Models\User::firstOrCreate(['email' => $this->option('email')], ['name' => 'Cloud Admin (enterprise)', 'password' => \Illuminate\Support\Str::random(64)]);
    $user->forceFill(['current_organization_id' => $org->id])->save();
    \App\Models\Membership::allTenants()->updateOrCreate(['organization_id' => $org->id, 'user_id' => $user->id], ['role' => 'enterprise_service']);
    $token = $user->createToken('cloud-admin-enterprise', ['enterprise.deploy'], now()->addDays((int) $this->option('days')));
    $token->accessToken->forceFill(['organization_id' => $org->id])->save();
    $this->line('Service account '.$user->email.' in '.$org->name.' (role enterprise_service).');
    $this->line('PANEL_API_TOKEN (shown once):');
    $this->line($token->plainTextToken);

    return 0;
})->purpose('Create the Cloud Admin service account and issue its enterprise.deploy API token');
Schedule::command('enterprise:sync')->everyMinute()->withoutOverlapping();
Schedule::command('enterprise:webhooks')->everyMinute()->withoutOverlapping();
Artisan::command('enterprise:updates', function (\App\Services\EnterpriseUpdateService $updates) {
    $this->info('Advanced '.$updates->tick().' enterprise update(s).');
})->purpose('Run due enterprise updates (backup, redeploy, health, rollback) and the auto/notify policies');
Schedule::command('enterprise:updates')->everyMinute()->withoutOverlapping();
Artisan::command('enterprise:images', function (\App\Services\EnterpriseProvisioningService $svc) {
    $this->info('Asked '.$svc->scanDeploymentNodes().' node(s) for their worksuite-server images.');
})->purpose('Scan the images on every node an enterprise deployment runs on (and the enterprise nodes) so new tags show on the Updates tab');
Schedule::command('enterprise:images')->everyTwoMinutes()->withoutOverlapping();
