<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Container;
use App\Models\Database;
use App\Models\Domain;
use App\Models\EnvVar;
use App\Models\Job;
use App\Models\Server;
use App\Models\SiteMigration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migration wizard (SRS MIG-001..011, UMIG): a cPanel, Plesk or HestiaCP backup is fetched onto the
 * target server and inspected (pre-flight); each site is mapped to a new app, which is
 * deployed on a temporary platform hostname and then filled with the site's files and
 * database. A checked site is cut over by DNS, can be rolled back, and can be re-synced.
 */
class MigrationService implements HandlesCommandResults
{
    /** Stands in for the database password in stored job steps; filled in at dispatch. */
    public const PASSWORD = '@db-password';

    public const APP_NAME = '/^[a-z0-9][a-z0-9-]{0,40}$/';

    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    /** MIG-001: start from a cPanel, Plesk or HestiaCP backup at a URL (an export link, a file share, object storage). */
    public function create(array $data, ?string $userId): SiteMigration
    {
        $server = Server::findOrFail($data['server_id']);
        if (! $server->isOnline()) {
            throw ApiException::conflict('The target server is offline.');
        }
        $m = SiteMigration::create([
            'server_id' => $server->id, 'source' => 'cpanel', 'archive_url' => $data['archive_url'],
            'archive_sha256' => $data['sha256'] ?? null, 'status' => 'fetching', 'created_by' => $userId,
        ]);
        $m->event('Migration created; fetching the backup');
        $this->audit->log('migration.create', 'site_migrations', $m->id, ['server' => $server->name, 'source' => 'cpanel']);
        $this->fetch($m, $userId);

        return $m;
    }

    /** MIG-011: several accounts at once, one migration each. */
    public function bulk(string $serverId, array $archives, ?string $userId): array
    {
        return array_map(fn ($a) => $this->create(['server_id' => $serverId, 'archive_url' => $a['url'], 'sha256' => $a['sha256'] ?? null], $userId), $archives);
    }

    /** UMIG-001: a customer asks for a migration; nothing runs until an administrator approves. */
    public function request(array $data, string $userId): SiteMigration
    {
        $m = SiteMigration::create([
            'source' => 'cpanel', 'archive_url' => $data['archive_url'], 'archive_sha256' => $data['sha256'] ?? null,
            'status' => 'requested', 'notes' => $data['notes'] ?? null, 'requested_by' => $userId, 'created_by' => $userId,
        ]);
        $m->event('Migration requested');
        $this->audit->log('migration.request', 'site_migrations', $m->id, []);

        return $m;
    }

    /** UMIG-002: approve onto a server (the fetch starts) or reject with a reason. */
    public function decide(SiteMigration $m, bool $approve, ?string $serverId, ?string $reason, ?string $userId): SiteMigration
    {
        if ($m->status !== 'requested') {
            throw ApiException::conflict('Only a pending request can be approved or rejected.');
        }
        if (! $approve) {
            $m->update(['status' => 'rejected', 'decision' => $reason]);
            $m->event('Request rejected'.($reason ? ': '.$reason : ''));
            $this->audit->log('migration.reject', 'site_migrations', $m->id, ['reason' => $reason]);

            return $m;
        }
        $server = Server::allTenants()->find($serverId) ?? throw ApiException::validation(['server_id' => ['Choose the server to migrate onto.']]);
        if (! $server->isOnline()) {
            throw ApiException::conflict('The target server is offline.');
        }
        $m->update(['status' => 'fetching', 'server_id' => $server->id, 'decision' => $reason]);
        $m->event('Request approved; fetching the backup');
        $this->audit->log('migration.approve', 'site_migrations', $m->id, ['server' => $server->name]);
        $this->fetch($m, $userId);

        return $m;
    }

    private function fetch(SiteMigration $m, ?string $userId, string $action = 'inspect'): Job
    {
        $steps = [['migrate.fetch', ['url' => $m->archive_url, 'sha256' => $m->archive_sha256 ?? '']]];
        if ($action === 'inspect') {
            $steps[] = ['migrate.inspect', []];
        } else {
            array_unshift($steps, ['migrate.cleanup', []]);
        }

        return $this->run($m, $action, $steps, $userId);
    }

    /**
     * MIG-009: delta sync. A newer backup of the same account is fetched and every copied site
     * is copied again: files of the same size are skipped, the databases are re-imported.
     */
    public function resync(SiteMigration $m, string $url, ?string $sha256, ?string $userId): Job
    {
        if (! in_array($m->status, ['migrated', 'migrating'], true) || ! collect($m->sites)->contains(fn ($s) => in_array($s['status'], ['migrated', 'live', 'failed'], true))) {
            throw ApiException::conflict('Nothing has been copied yet.');
        }
        $m->update(['archive_url' => $url, 'archive_sha256' => $sha256]);
        $m->event('Fetching a newer backup for a delta sync');
        $this->audit->log('migration.resync', 'site_migrations', $m->id, []);

        return $this->fetch($m, $userId, 'resync');
    }

    /**
     * MIG-007 / MIG-010: point the domain at the new app. The site must answer on its temporary
     * address first. In a DNS zone the panel manages, the old records are kept for a rollback
     * and replaced; otherwise the domain is added and the records to set are returned.
     */
    public function cutover(SiteMigration $m, int $i, ?string $userId): SiteMigration
    {
        $s = $m->sites[$i] ?? throw ApiException::validation(['site' => ['No such site.']]);
        if ($s['status'] !== 'migrated') {
            throw ApiException::conflict('Only a copied site can be cut over.');
        }
        if ($error = $this->check($s['temp_url'] ?? '')) {
            throw ApiException::conflict("The new site does not answer on {$s['temp_url']} ({$error}); fix it before moving DNS.");
        }
        $zone = \App\Models\DnsZone::forDomain($s['domain'], $m->organization_id);
        $previous = [];
        if ($zone) {
            foreach ($zone->records()->withoutGlobalScope('organization')->where('name', $s['domain'])->whereIn('type', ['A', 'AAAA', 'CNAME'])->get() as $r) {
                $previous[] = ['type' => $r->type, 'content' => $r->content, 'ttl' => $r->ttl, 'proxied' => $r->proxied];
                app(DnsZoneService::class)->deleteRecord($zone, $r);
            }
        }
        $domain = app(DomainService::class)->create(['name' => $s['domain'], 'application_id' => $s['app_id'], 'target_port' => 80]);
        $sites = $m->sites;
        $sites[$i] = array_merge($s, ['status' => 'live', 'domain_id' => $domain->id, 'zone_id' => $zone?->id, 'previous_records' => $previous,
            'dns_records' => $zone ? [] : app(DomainService::class)->expectedRecords($domain), 'cutover_at' => now()->toIso8601ZuluString()]);
        $m->update(['sites' => $sites]);
        $m->event($zone ? "{$s['domain']} now points at the new app (DNS updated in the {$zone->name} zone)" : "{$s['domain']} added; change its DNS records to finish the cutover");
        $this->audit->log('migration.cutover', 'site_migrations', $m->id, ['domain' => $s['domain'], 'zone' => $zone?->name, 'previous' => $previous]);

        return $m->fresh();
    }

    /** MIG-008: undo a cutover. The old DNS records come back; the new app stays for another try. */
    public function rollback(SiteMigration $m, int $i, ?string $userId): SiteMigration
    {
        $s = $m->sites[$i] ?? throw ApiException::validation(['site' => ['No such site.']]);
        if ($s['status'] !== 'live') {
            throw ApiException::conflict('Only a cut-over site can be rolled back.');
        }
        $zone = $s['zone_id'] ? \App\Models\DnsZone::allTenants()->with(['provider' => fn ($q) => $q->withoutGlobalScope('organization')])->find($s['zone_id']) : null;
        if ($zone) {
            foreach ($zone->records()->withoutGlobalScope('organization')->where('name', $s['domain'])->whereIn('type', ['A', 'AAAA', 'CNAME'])->get() as $r) {
                app(DnsZoneService::class)->deleteRecord($zone, $r);
            }
            foreach ($s['previous_records'] as $r) {
                app(DnsZoneService::class)->createRecord($zone, ['type' => $r['type'], 'name' => $s['domain'], 'content' => $r['content'], 'ttl' => $r['ttl'], 'proxied' => (bool) $r['proxied']]);
            }
        }
        Domain::allTenants()->find($s['domain_id'])?->delete();
        $sites = $m->sites;
        $sites[$i] = array_merge(array_diff_key($s, array_flip(['domain_id', 'zone_id', 'previous_records', 'dns_records', 'cutover_at'])), ['status' => 'migrated']);
        $m->update(['sites' => $sites]);
        $m->event("Rolled back {$s['domain']}".($zone ? '; the old DNS records are restored' : '; point its DNS back at the old server'));
        $this->audit->log('migration.rollback', 'site_migrations', $m->id, ['domain' => $s['domain']]);

        return $m->fresh();
    }

    /** Null when the URL answers without a server error. */
    private function check(string $url): ?string
    {
        try {
            $res = \Illuminate\Support\Facades\Http::timeout(20)->get($url);
        } catch (\Throwable) {
            return 'no answer';
        }

        return $res->serverError() ? 'HTTP '.$res->status() : null;
    }

    /** MIG-002: what would block or change the migration, per site. */
    public function preflight(SiteMigration $m): array
    {
        $checks = [];
        $archiveDbs = $m->inventory['databases'] ?? [];
        foreach ($m->sites ?? [] as $s) {
            if (! $s['enabled']) {
                continue;
            }
            $issues = [];
            if (Domain::allTenants()->where('name', $s['domain'])->exists()) {
                $issues[] = 'The domain is already on the platform.';
            }
            if (Application::allTenants()->withTrashed()->where('name', $s['app_name'])->where('id', '!=', $s['app_id'] ?? '')->exists()) {
                $issues[] = "An app named {$s['app_name']} already exists.";
            }
            if (($s['docroot'] ?? '') === '') {
                $issues[] = 'The document root is not known; set one.';
            }
            if ($s['database'] && ! in_array($s['database'], $archiveDbs, true)) {
                $issues[] = "The backup has no database {$s['database']}.";
            }
            if ($s['database'] && ! $s['target_database_id']) {
                $issues[] = 'Choose a MySQL or MariaDB database on the target server to import into.';
            }
            if ($s['wordpress'] && ! $s['database']) {
                $issues[] = 'WordPress needs its database.';
            }
            $checks[] = ['domain' => $s['domain'], 'ok' => ! $issues, 'issues' => $issues];
        }

        return $checks;
    }

    /** MIG-003: which sites move, under which app name, with which database. */
    public function map(SiteMigration $m, array $sites): SiteMigration
    {
        if ($m->status !== 'ready') {
            throw ApiException::conflict('Sites can be mapped only before the migration runs.');
        }
        $byDomain = collect($sites)->keyBy('domain');
        $mapped = [];
        foreach ($m->sites ?? [] as $s) {
            $in = $byDomain[$s['domain']] ?? [];
            $s['enabled'] = (bool) ($in['enabled'] ?? $s['enabled']);
            if (isset($in['app_name'])) {
                $s['app_name'] = preg_match(self::APP_NAME, $in['app_name']) ? $in['app_name'] : throw ApiException::validation(['app_name' => ["{$in['app_name']} is not a valid app name."]]);
            }
            if (array_key_exists('docroot', $in)) {
                $s['docroot'] = trim((string) $in['docroot'], '/');
            }
            if (array_key_exists('database', $in)) {
                $s['database'] = $in['database'] ?: null;
            }
            if (array_key_exists('target_database_id', $in)) {
                $db = $in['target_database_id'] ? Database::where('server_id', $m->server_id)->whereIn('engine', ['mysql', 'mariadb'])->find($in['target_database_id']) : null;
                if ($in['target_database_id'] && ! $db) {
                    throw ApiException::validation(['target_database_id' => ['Choose a MySQL or MariaDB database on the target server.']]);
                }
                $s['target_database_id'] = $db?->id;
            }
            $mapped[] = $s;
        }
        $m->update(['sites' => $mapped]);

        return $m;
    }

    /** MIG-004: create and deploy an app per site; each is filled once it is running. */
    public function start(SiteMigration $m, ?string $userId): SiteMigration
    {
        if ($m->status !== 'ready') {
            throw ApiException::conflict('The migration is not ready to run.');
        }
        $enabled = collect($m->sites ?? [])->where('enabled', true);
        if ($enabled->isEmpty()) {
            throw ApiException::conflict('Choose at least one site to migrate.');
        }
        if ($blocked = collect($this->preflight($m))->firstWhere('ok', false)) {
            throw ApiException::conflict("Fix the pre-flight issues for {$blocked['domain']} first: ".implode(' ', $blocked['issues']));
        }
        $user = $userId ? User::find($userId) : null;
        $sites = DB::transaction(function () use ($m) {
            $sites = [];
            foreach ($m->sites as $s) {
                if ($s['enabled']) {
                    $app = Application::create([
                        'server_id' => $m->server_id, 'name' => $s['app_name'], 'kind' => 'admin', 'source_type' => 'image',
                        'source' => ['type' => 'image', 'image' => $s['wordpress'] ? 'wordpress:6' : 'php:8.3-apache'],
                        'image' => $s['wordpress'] ? 'wordpress:6' : 'php:8.3-apache', 'strategy' => 'recreate', 'environment' => 'production',
                        'port' => 80, 'volume_path' => '/var/www/html', 'status' => 'deploying',
                    ]);
                    if ($s['wordpress'] && ($db = $this->targetDb($s))) {
                        foreach (['WORDPRESS_DB_HOST' => $this->dbContainer($db), 'WORDPRESS_DB_NAME' => $this->dbName($s), 'WORDPRESS_DB_USER' => 'root'] as $k => $v) {
                            EnvVar::create(['application_id' => $app->id, 'key' => $k, 'value' => $v, 'secret' => false]);
                        }
                        EnvVar::create(['application_id' => $app->id, 'key' => 'WORDPRESS_DB_PASSWORD', 'value' => $this->passwordOf($db), 'secret' => true]);
                    }
                    // MIG-005: a temporary hostname to check the site before DNS moves.
                    $host = $app->name.'.'.config('platform.domain');
                    app(DomainService::class)->create(['name' => $host, 'application_id' => $app->id, 'target_port' => 80, 'platform_subdomain' => true], true);
                    $s += ['app_id' => $app->id, 'temp_url' => 'https://'.$host];
                    $s['status'] = 'deploying';
                }
                $sites[] = $s;
            }
            $m->update(['sites' => $sites, 'status' => 'migrating']);

            return $sites;
        });
        foreach ($sites as $s) {
            if ($s['enabled']) {
                app(DeploymentService::class)->start(Application::findOrFail($s['app_id']), $user, 'manual');
                $m->event("Deploying {$s['app_name']} for {$s['domain']}");
            }
        }
        $this->audit->log('migration.start', 'site_migrations', $m->id, ['sites' => $enabled->pluck('domain')->values()->all()]);

        return $m->fresh();
    }

    /** Called after every successful deployment: copies the site into a migrated app. */
    public function afterDeployment(Application $app): void
    {
        foreach (SiteMigration::allTenants()->where('status', 'migrating')->get() as $m) {
            foreach ($m->sites as $i => $s) {
                if (($s['app_id'] ?? null) === $app->id && $s['status'] === 'deploying') {
                    $this->copySite($m, $i);
                }
            }
        }
    }

    /** MIG-004: files, then the database, then WordPress's own settings. Re-runnable: the copy resumes. */
    public function copySite(SiteMigration $m, int $i, ?string $userId = null): Job
    {
        $s = $m->sites[$i];
        $app = Application::allTenants()->findOrFail($s['app_id']);
        $steps = [['migrate.extract', ['from' => $s['docroot'], 'volume' => 'app-'.$app->name.'-data', 'owner' => 33]]];
        if ($s['database'] && ($db = $this->targetDb($s))) {
            $steps[] = ['migrate.import_db', ['sql' => $s['database'], 'db' => [
                'engine' => $db->engine, 'container' => $this->dbContainer($db), 'database' => $this->dbName($s), 'user' => 'root',
            ], 'password_of' => $db->id]];
            if ($s['wordpress']) {
                $container = Container::allTenants()->where('application_id', $app->id)->where('status', 'running')->value('name') ?? $app->containerName();
                foreach (['DB_HOST' => $this->dbContainer($db), 'DB_NAME' => $this->dbName($s), 'DB_USER' => 'root', 'DB_PASSWORD' => self::PASSWORD] as $k => $v) {
                    $steps[] = ['wp.cli', ['container' => $container, 'url' => '', 'args' => ['config', 'set', $k, $v], 'password_of' => $db->id]];
                }
            }
        }
        $sites = $m->sites;
        $sites[$i]['was_live'] = $sites[$i]['status'] === 'live' || ($sites[$i]['was_live'] ?? false);
        $sites[$i]['status'] = 'copying';
        $m->update(['sites' => $sites]);
        $m->event("Copying {$s['domain']} into {$app->name}");

        return $this->run($m, 'copy', $steps, $userId, ['site' => $i]);
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $m = SiteMigration::allTenants()->find($job->payload['migration_id'] ?? null);
        if (! $m) {
            $this->jobs->fail($job, 'The migration no longer exists.', 'execute');

            return;
        }
        $site = $job->payload['site'] ?? null;
        if ($command->status !== 'succeeded') {
            $message = (string) ($command->message ?: 'The migration step failed.');
            if ($site !== null) {
                $sites = $m->sites;
                $sites[$site]['status'] = 'failed';
                $sites[$site]['error'] = $message;
                $m->update(['sites' => $sites]);
            } else {
                $m->update(['status' => 'failed', 'error' => $message]);
            }
            $m->event('Failed: '.$message);
            $this->jobs->fail($job, $message, 'execute');

            return;
        }
        $out = (array) $command->output;
        match ($command->type) {
            'migrate.fetch' => $m->event('Backup fetched ('.number_format(((int) ($out['bytes'] ?? 0)) / 1048576, 1).' MB)'),
            'migrate.inspect' => $this->saveInventory($m, $out),
            'migrate.extract' => $m->event('Copied '.((int) ($out['files'] ?? 0)).' files'.(($out['resumed'] ?? 0) ? ' ('.$out['resumed'].' already there)' : '')),
            'migrate.import_db' => $m->event('Imported database '.($out['database'] ?? '')),
            default => null,
        };
        $steps = $job->payload['steps'];
        $next = (int) $job->payload['step'] + 1;
        if ($next < count($steps)) {
            $job->update(['payload' => ['step' => $next] + $job->payload]);
            $this->dispatch($m, $job, $steps[$next]);

            return;
        }
        if (($job->payload['action'] ?? null) === 'resync') {
            $this->jobs->succeed($job, ['migration_id' => $m->id]);
            foreach ($m->fresh()->sites as $i => $s) {
                if (in_array($s['status'], ['migrated', 'live', 'failed'], true) && isset($s['app_id'])) {
                    $this->copySite($m->fresh(), $i, $job->user_id);
                }
            }

            return;
        }
        if ($site !== null) {
            $sites = $m->fresh()->sites;
            $sites[$site]['status'] = ($sites[$site]['was_live'] ?? false) ? 'live' : 'migrated';
            unset($sites[$site]['was_live']);
            unset($sites[$site]['error']);
            $done = collect($sites)->where('enabled', true)->every(fn ($s) => in_array($s['status'], ['migrated', 'live'], true));
            $m->update(['sites' => $sites, 'status' => $done ? 'migrated' : $m->status]);
            $m->event("{$sites[$site]['domain']} is migrated; check it at {$sites[$site]['temp_url']}");
        }
        $this->jobs->succeed($job, ['migration_id' => $m->id]);
    }

    private function saveInventory(SiteMigration $m, array $inv): void
    {
        $dbs = (array) ($inv['databases'] ?? []);
        $sites = [];
        foreach ((array) ($inv['sites'] ?? []) as $s) {
            $base = Str::slug(Str::before($s['domain'], '.') === 'www' ? Str::after($s['domain'], '.') : $s['domain']);
            $sites[] = [
                'domain' => $s['domain'], 'kind' => $s['kind'], 'docroot' => $s['docroot'], 'wordpress' => (bool) $s['wordpress'],
                'enabled' => $s['docroot'] !== '' && $s['kind'] !== 'subdomain', 'app_name' => substr($base, 0, 40),
                'database' => $s['wordpress'] && count($dbs) === 1 ? $dbs[0] : null, 'target_database_id' => null, 'status' => 'pending',
            ];
        }
        // The agent tells the format apart (cPanel, HestiaCP, Plesk) from the archive itself.
        $source = in_array($inv['source'] ?? null, ['cpanel', 'hestia', 'plesk'], true) ? $inv['source'] : $m->source;
        $m->update(['inventory' => $inv, 'sites' => $sites, 'status' => 'ready', 'source' => $source]);
        $m->event(sprintf('Inspected: %d sites, %d databases, %s files', count($sites), count($dbs), number_format((int) ($inv['files'] ?? 0))));
    }

    private function targetDb(array $s): ?Database
    {
        return $s['target_database_id'] ? Database::allTenants()->find($s['target_database_id']) : null;
    }

    private function passwordOf(?Database $db): string
    {
        return (string) ($db ? \App\Models\Secret::allTenants()->find($db->secret_id)?->value : '');
    }

    private function dbContainer(Database $db): string
    {
        return Container::allTenants()->find($db->container_id)?->name ?? 'db-'.Str::slug($db->name);
    }

    /** The new database is named after the app. */
    private function dbName(array $s): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_]/', '_', $s['app_name']), 0, 64);
    }

    private function run(SiteMigration $m, string $action, array $steps, ?string $userId, array $extra = []): Job
    {
        $job = $this->jobs->create('migration', ['execute'], $m, ['migration_id' => $m->id, 'action' => $action, 'steps' => $steps, 'step' => 0] + $extra, $userId, $m->organization_id);
        $this->dispatch($m, $job, $steps[0]);

        return $job;
    }

    private function dispatch(SiteMigration $m, Job $job, array $step): void
    {
        [$type, $payload] = $step;
        if (str_starts_with($type, 'migrate.')) {
            $payload['id'] = $m->id;
        }
        // Passwords never sit in the job; the agent command payload is stored encrypted.
        if ($dbId = $payload['password_of'] ?? null) {
            $password = $this->passwordOf(Database::allTenants()->find($dbId));
            unset($payload['password_of']);
            if (isset($payload['db'])) {
                $payload['db']['password'] = $password;
            }
            if (isset($payload['args'])) {
                $payload['args'] = array_map(fn ($a) => $a === self::PASSWORD ? $password : $a, $payload['args']);
            }
        }
        $this->bus->dispatch(Server::allTenants()->find($m->server_id), $type, $payload, $job);
    }
}
