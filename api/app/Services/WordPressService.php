<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Container;
use App\Models\Domain;
use App\Models\EnvVar;
use App\Models\Job;
use App\Models\Server;
use App\Models\WpRestorePoint;
use App\Models\User;
use App\Models\WpSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WordPress toolkit (SRS WP). Every action is WP-CLI run by the agent beside the site's
 * container (agent `wp.cli` / `wp.inventory`). An action of several steps is one job
 * whose steps run in order, each after the previous one succeeded.
 */
class WordPressService implements HandlesCommandResults
{
    /** WP-003: per-site switches and the WP-CLI steps that set them. */
    public const SETTINGS = ['maintenance', 'debug', 'indexing', 'auto_minor', 'platform_cron', 'redis'];

    /** WP-009: hardening switches, applied by a must-use plugin and wp-config. */
    public const HARDENING = ['xmlrpc', 'file_edit', 'user_enum', 'security_headers', 'limit_logins'];

    /** Restore points kept per site; older dumps are deleted. */
    public const KEEP_RESTORE_POINTS = 5;

    /** WP-010: staging tables live beside the live ones under this prefix. */
    public const STAGING_PREFIX = 'stg_';

    public const SLUG = '/^[a-z0-9][a-z0-9_.-]{0,99}$/';

    public function __construct(private CommandBus $bus, private JobService $jobs, private AuditLogger $audit) {}

    /** WP-001: every WordPress app gets a row; each is inventoried. Returns the inventories queued. */
    public function detect(): int
    {
        $n = 0;
        foreach (Application::allTenants()->where('image', 'like', 'wordpress%')->whereNotNull('server_id')->get() as $app) {
            $site = WpSite::allTenants()->firstOrCreate(['application_id' => $app->id], ['organization_id' => $app->organization_id, 'server_id' => $app->server_id]);
            if (Server::allTenants()->find($site->server_id)?->isOnline()) {
                $this->inventory($site);
                $n++;
            }
        }

        return $n;
    }

    public function inventory(WpSite $site, ?string $userId = null): Job
    {
        return $this->run($site, 'inventory', [['wp.inventory', []]], [], $userId);
    }

    /** WP-003: turns one switch on or off. */
    public function setting(WpSite $site, string $key, bool $on, ?string $userId): Job
    {
        $steps = match ($key) {
            'maintenance' => [['wp.cli', ['args' => ['maintenance-mode', $on ? 'activate' : 'deactivate']]]],
            'debug' => [['wp.cli', ['args' => ['config', 'set', 'WP_DEBUG', $on ? 'true' : 'false', '--raw']]]],
            'indexing' => [['wp.cli', ['args' => ['option', 'update', 'blog_public', $on ? '1' : '0']]]],
            'auto_minor' => [['wp.cli', ['args' => $on ? ['config', 'set', 'WP_AUTO_UPDATE_CORE', 'minor'] : ['config', 'set', 'WP_AUTO_UPDATE_CORE', 'false', '--raw']]]],
            // The platform runs due events every 5 minutes instead of on page loads.
            'platform_cron' => [['wp.cli', ['args' => ['config', 'set', 'DISABLE_WP_CRON', $on ? 'true' : 'false', '--raw']]]],
            'redis' => $on ? [
                ['wp.cli', ['args' => ['config', 'set', 'WP_REDIS_HOST', (string) config('platform.wordpress.redis_host')]]],
                ['wp.cli', ['args' => ['plugin', 'install', 'redis-cache', '--activate']]],
                ['wp.cli', ['args' => ['redis', 'enable']]],
            ] : [['wp.cli', ['args' => ['redis', 'disable']]]],
            default => throw ApiException::validation(['setting' => ['Unknown setting.']]),
        };
        if ($key === 'redis' && $on && ! config('platform.wordpress.redis_host')) {
            throw ApiException::conflict('Set WORDPRESS_REDIS_HOST to a Redis server the sites can reach before turning on the object cache.');
        }
        $this->audit->log('wordpress.setting', 'wp_sites', $site->id, ['setting' => $key, 'on' => $on]);

        return $this->run($site, 'setting', $steps, ['setting' => $key, 'on' => $on], $userId);
    }

    /** WP-004 / WP-007: one-step tools; the WP-CLI output comes back on the job. */
    public function tool(WpSite $site, string $tool, array $input, ?string $userId): Job
    {
        $slug = fn (string $key) => preg_match(self::SLUG, (string) ($input[$key] ?? '')) ? $input[$key] : throw ApiException::validation([$key => ['Not a valid plugin or theme name.']]);
        $args = match ($tool) {
            'flush_cache' => ['cache', 'flush'],
            'verify_checksums' => ['core', 'verify-checksums'],
            'rotate_salts' => ['config', 'shuffle-salts'],
            'search_replace' => array_merge(['search-replace', (string) $input['search'], (string) $input['replace'], '--report-changed-only', '--skip-columns=guid'], ($input['dry_run'] ?? true) ? ['--dry-run'] : []),
            'plugin_activate' => ['plugin', 'activate', $slug('plugin')],
            'plugin_deactivate' => ['plugin', 'deactivate', $slug('plugin')],
            'theme_activate' => ['theme', 'activate', $slug('theme')],
            default => throw ApiException::validation(['tool' => ['Unknown tool.']]),
        };
        $this->audit->log('wordpress.'.$tool, 'wp_sites', $site->id, array_filter([
            'search' => $input['search'] ?? null, 'replace' => $input['replace'] ?? null, 'dry_run' => $tool === 'search_replace' ? (bool) ($input['dry_run'] ?? true) : null,
            'plugin' => $input['plugin'] ?? null, 'theme' => $input['theme'] ?? null,
        ], fn ($v) => $v !== null));
        // Activations change what the site lists, so refresh it afterwards.
        $steps = [['wp.cli', ['args' => $args]]];
        if (str_starts_with($tool, 'plugin_') || $tool === 'theme_activate') {
            $steps[] = ['wp.inventory', []];
        }

        return $this->run($site, $tool, $steps, [], $userId);
    }

    /** WP-004: a new random admin password, returned once and never stored or logged. */
    public function resetPassword(WpSite $site, string $login, ?string $userId): array
    {
        if (! preg_match('/^[A-Za-z0-9_.@-]{1,60}$/', $login)) {
            throw ApiException::validation(['user' => ['Not a valid user login.']]);
        }
        $password = Str::password(20, symbols: false);
        // The job keeps a redacted copy of the step; the password travels only in the
        // agent command, whose payload is stored encrypted.
        $job = $this->run($site, 'reset_password', [['wp.cli', ['args' => ['user', 'update', $login, '--user_pass=********', '--skip-email']]]], ['user' => $login], $userId,
            first: ['wp.cli', ['args' => ['user', 'update', $login, '--user_pass='.$password, '--skip-email']]]);
        $this->audit->log('wordpress.reset_password', 'wp_sites', $site->id, ['user' => $login]);

        return [$job, $password];
    }

    /**
     * WP-012: a one-time admin login link valid for 60 seconds. A small must-use plugin
     * checks the token against its hash, deletes it, and signs in the first administrator.
     */
    public function loginLink(WpSite $site, ?string $userId): array
    {
        if (! $site->url) {
            throw ApiException::conflict('The site address is not known yet; refresh the site first.');
        }
        $token = Str::random(48);
        $app = Application::allTenants()->find($site->application_id);
        $job = $this->run($site, 'login_link', [
            ['fs.write', ['volume' => 'app-'.$app->name.'-data', 'path' => 'wp-content/mu-plugins/ws-login.php', 'content' => self::loginPlugin()]],
            ['wp.cli', ['args' => ['option', 'update', 'ws_login_token', hash('sha256', $token).':'.(time() + 60), '--autoload=no']]],
        ], [], $userId);
        $this->audit->log('wordpress.login_link', 'wp_sites', $site->id, ['expires_in' => 60]);

        return [$job, rtrim($site->url, '/').'/?ws_login='.$token];
    }

    public static function loginPlugin(): string
    {
        return <<<'PHP'
<?php
/* WorkSuite CloudPanel one-time admin login (WP-012). Managed by the panel; safe to delete. */
add_action('init', function () {
    if (empty($_GET['ws_login']) || !is_string($_GET['ws_login'])) { return; }
    $stored = (string) get_option('ws_login_token', '');
    delete_option('ws_login_token');
    [$hash, $expires] = array_pad(explode(':', $stored, 2), 2, '0');
    if ($hash === '' || time() > (int) $expires || !hash_equals($hash, hash('sha256', $_GET['ws_login']))) {
        wp_die('This login link has expired or was already used.', 403);
    }
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
    if (!$admins) { wp_die('No administrator found.', 403); }
    wp_set_auth_cookie($admins[0]->ID, false);
    wp_safe_redirect(admin_url());
    exit;
});
PHP;
    }

    /** Platform-run WP-Cron (WP-003): due events of every site that switched page-load cron off. */
    /**
     * WP-005 / WP-006: update core, plugins or themes. A restore point is taken first, the
     * site is checked over HTTP afterwards, and a failed check rolls the update back.
     */
    public function update(WpSite $site, string $scope, array $items, ?string $userId): Job
    {
        $pick = function (string $kind) use ($site, $items) {
            $names = collect($kind === 'plugin' ? $site->plugins : $site->themes)->where('update', 'available')->pluck('name');
            if ($items) {
                $names = $names->intersect($items);
            }

            return $names->filter(fn ($n) => preg_match(self::SLUG, $n))->values()->all();
        };
        $touched = ['core' => in_array($scope, ['core', 'all'], true) && $site->core_update, 'plugins' => [], 'themes' => []];
        $updates = [];
        if ($touched['core']) {
            $updates[] = ['wp.cli', ['args' => ['core', 'update']]];
            $updates[] = ['wp.cli', ['args' => ['core', 'update-db']]];
        }
        foreach (['plugin' => 'plugins', 'theme' => 'themes'] as $kind => $key) {
            if (in_array($scope, [$key, 'all'], true) && ($names = $pick($kind))) {
                $touched[$key] = $names;
                $updates[] = ['wp.cli', ['args' => array_merge([$kind, 'update'], $names)]];
            }
        }
        if (! $updates) {
            throw ApiException::conflict('There is nothing to update on this site.');
        }
        $point = $this->newRestorePoint($site, 'update', $userId);
        $steps = array_merge($this->restorePointSteps($site, $point), $updates, [
            ['local', ['op' => 'health', 'restore_point' => $point->id, 'touched' => $touched]],
            ['wp.inventory', []],
        ]);
        $this->audit->log('wordpress.update', 'wp_sites', $site->id, ['scope' => $scope, 'core' => (bool) $touched['core'], 'plugins' => $touched['plugins'], 'themes' => $touched['themes']]);

        return $this->run($site, 'update', $steps, ['scope' => $scope, 'restore_point_id' => $point->id], $userId);
    }

    /** WP-006: "Update all sites": each online site with updates gets its own job. */
    public function updateAll(string $scope, ?string $userId): array
    {
        $jobs = [];
        foreach (WpSite::query()->where('updates_count', '>', 0)->get() as $site) {
            try {
                $jobs[] = $this->update($site, $scope, [], $userId)->id;
            } catch (ApiException) {
                // Offline server, or nothing in this scope: skip the site.
            }
        }

        return $jobs;
    }

    /** WP-011: a manual restore point. */
    public function createRestorePoint(WpSite $site, ?string $userId): Job
    {
        $point = $this->newRestorePoint($site, 'manual', $userId);
        $this->audit->log('wordpress.restore_point', 'wp_sites', $site->id, ['restore_point' => $point->id]);

        return $this->run($site, 'restore_point', $this->restorePointSteps($site, $point), ['restore_point_id' => $point->id], $userId);
    }

    /** WP-011: one-click restore of the database and the versions in a restore point. */
    public function restore(WpSite $site, WpRestorePoint $point, ?string $userId): Job
    {
        if (! in_array($point->status, ['ready', 'restored'], true)) {
            throw ApiException::conflict('Only a completed restore point can be restored.');
        }
        $this->audit->log('wordpress.restore', 'wp_sites', $site->id, ['restore_point' => $point->id]);

        return $this->run($site, 'restore', array_merge($this->rollbackSteps($site, $point, null), [['wp.inventory', []]]), ['restore_point_id' => $point->id], $userId);
    }

    /** WP-009: turn hardening measures on or off; one must-use plugin carries them all. */
    public function harden(WpSite $site, array $changes, ?string $userId): Job
    {
        $state = array_replace(array_fill_keys(self::HARDENING, false), $site->settings['hardening'] ?? [], array_intersect_key(array_map('boolval', $changes), array_flip(self::HARDENING)));
        $app = Application::allTenants()->find($site->application_id);
        $steps = [
            ['fs.mkdir', ['volume' => 'app-'.$app->name.'-data', 'path' => 'wp-content/mu-plugins', 'exist_ok' => true]],
            ['fs.write', ['volume' => 'app-'.$app->name.'-data', 'path' => 'wp-content/mu-plugins/ws-hardening.php', 'content' => self::hardeningPlugin($state)]],
            ['wp.cli', ['args' => $state['file_edit'] ? ['config', 'set', 'DISALLOW_FILE_EDIT', 'true', '--raw'] : ['config', 'delete', 'DISALLOW_FILE_EDIT']]],
        ];
        if (! $state['file_edit'] && ! ($site->settings['hardening']['file_edit'] ?? false)) {
            array_pop($steps); // never set, nothing to delete
        }
        $this->audit->log('wordpress.hardening', 'wp_sites', $site->id, $state);

        return $this->run($site, 'hardening', $steps, ['hardening' => $state], $userId);
    }

    public static function hardeningPlugin(array $on): string
    {
        $flags = var_export(array_map('boolval', $on), true);

        return <<<PHP
<?php
// Managed by WorkSuite CloudPanel (WordPress hardening). Changes here are overwritten.
\$ws = {$flags};
if (\$ws['xmlrpc']) {
    add_filter('xmlrpc_enabled', '__return_false');
    add_filter('wp_headers', function (\$h) { unset(\$h['X-Pingback']); return \$h; });
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) { status_header(403); exit; }
}
if (\$ws['user_enum']) {
    add_filter('rest_endpoints', function (\$e) {
        if (! is_user_logged_in()) { unset(\$e['/wp/v2/users'], \$e['/wp/v2/users/(?P<id>[\\d]+)']); }
        return \$e;
    });
    add_action('template_redirect', function () {
        if (! is_admin() && isset(\$_GET['author'])) { wp_safe_redirect(home_url('/'), 301); exit; }
    });
}
if (\$ws['security_headers']) {
    add_action('send_headers', function () {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    });
}
if (\$ws['limit_logins']) {
    \$wsKey = function () { return 'ws_ll_' . md5(\$_SERVER['REMOTE_ADDR'] ?? ''); };
    add_filter('authenticate', function (\$user) use (\$wsKey) {
        if ((int) get_transient(\$wsKey()) >= 5) {
            return new WP_Error('ws_locked', 'Too many failed logins. Try again in 15 minutes.');
        }
        return \$user;
    }, 30);
    add_action('wp_login_failed', function () use (\$wsKey) {
        set_transient(\$wsKey(), (int) get_transient(\$wsKey()) + 1, 900);
    });
}

PHP;
    }

    private function newRestorePoint(WpSite $site, string $reason, ?string $userId): WpRestorePoint
    {
        return WpRestorePoint::allTenants()->create([
            'organization_id' => $site->organization_id, 'wp_site_id' => $site->id, 'reason' => $reason, 'created_by' => $userId,
            'version' => $site->version,
            'plugins' => collect($site->plugins ?? [])->map(fn ($p) => ['name' => $p['name'], 'version' => $p['version'] ?? null])->values()->all(),
            'themes' => collect($site->themes ?? [])->map(fn ($t) => ['name' => $t['name'], 'version' => $t['version'] ?? null])->values()->all(),
        ]);
    }

    /** Dump the database into the site's restore folder, then prune old points. */
    private function restorePointSteps(WpSite $site, WpRestorePoint $point): array
    {
        $volume = 'app-'.Application::allTenants()->find($site->application_id)->name.'-data';
        $steps = [
            ['fs.mkdir', ['volume' => $volume, 'path' => 'wp-content/ws-restore', 'exist_ok' => true]],
            ['fs.write', ['volume' => $volume, 'path' => 'wp-content/ws-restore/.htaccess', 'content' => "Require all denied\n"]],
            ['fs.write', ['volume' => $volume, 'path' => 'wp-content/ws-restore/index.php', 'content' => "<?php\nhttp_response_code(404);\n"]],
            ['wp.cli', ['args' => ['db', 'export', $point->dump()]]],
            ['local', ['op' => 'ready', 'restore_point' => $point->id]],
        ];
        $old = WpRestorePoint::allTenants()->where('wp_site_id', $site->id)->whereKeyNot($point->id)
            ->orderByDesc('created_at')->orderByDesc('id')->skip(self::KEEP_RESTORE_POINTS - 1)->take(100)->get();
        foreach ($old as $o) {
            if ($o->status !== 'pending') {
                $steps[] = ['fs.delete', ['volume' => $volume, 'path' => $o->dump()]];
            }
            $steps[] = ['local', ['op' => 'forget', 'restore_point' => $o->id]];
        }

        return $steps;
    }

    /**
     * Import the dump and put back the versions that changed. $touched names what an update
     * changed; without it (a manual restore) everything whose version differs is reinstalled.
     */
    private function rollbackSteps(WpSite $site, WpRestorePoint $point, ?array $touched): array
    {
        $steps = [['wp.cli', ['args' => ['db', 'import', $point->dump()]]]];
        if ($point->version && ($touched ? $touched['core'] : $point->version !== $site->version)) {
            $steps[] = ['wp.cli', ['args' => ['core', 'update', '--version='.$point->version, '--force']]];
        }
        foreach (['plugin' => 'plugins', 'theme' => 'themes'] as $kind => $key) {
            $now = collect($site->{$key} ?? [])->pluck('version', 'name');
            foreach ($point->{$key} ?? [] as $item) {
                $changed = $touched ? in_array($item['name'], $touched[$key], true) : ($now[$item['name']] ?? null) !== $item['version'];
                if ($changed && $item['version'] && preg_match(self::SLUG, $item['name'])) {
                    $steps[] = ['wp.cli', ['args' => [$kind, 'install', $item['name'], '--version='.$item['version'], '--force']]];
                }
            }
        }
        $steps[] = ['local', ['op' => 'restored', 'restore_point' => $point->id]];

        return $steps;
    }

    /** Steps the API runs itself, between agent commands. */
    private function localStep(WpSite $site, Job $job, array $p): void
    {
        $point = WpRestorePoint::allTenants()->find($p['restore_point'] ?? null);
        match ($p['op']) {
            'ready' => $point?->update(['status' => 'ready']),
            'restored' => $point?->update(['status' => 'restored', 'restored_at' => now()]),
            'forget' => $point?->delete(),
            'staging' => $this->stagingStatus($p['site'], $p['status']),
            'health' => null,
        };
        if ($p['op'] === 'health' && ($error = $this->healthCheck($site))) {
            // Swap what is left of the plan for the rollback, and fail the job at the end.
            $done = array_slice($job->payload['steps'], 0, (int) $job->payload['step'] + 1);
            $job->update(['payload' => array_merge($job->payload, [
                'steps' => array_merge($done, $this->rollbackSteps($site, $point, $p['touched']), [['wp.inventory', []]]),
                'health_error' => 'the site failed its health check ('.$error.')',
            ])]);
        }
        $this->advance($site, $job->fresh());
    }

    private function stagingStatus(string $id, string $status): void
    {
        $staging = WpSite::allTenants()->find($id);
        if ($status === 'deleted' && $staging) {
            Application::allTenants()->find($staging->application_id)?->retire();
            WpRestorePoint::allTenants()->where('wp_site_id', $staging->id)->delete();
            $staging->delete();

            return;
        }
        $staging?->update(['staging_status' => $status]);
    }

    /** Null when the site answers normally; otherwise what went wrong. */
    public function healthCheck(WpSite $site): ?string
    {
        if (! $site->url) {
            return null;
        }
        try {
            $res = Http::timeout(20)->withOptions(['allow_redirects' => ['max' => 5]])->get($site->url);
        } catch (\Throwable $e) {
            return 'the site did not answer';
        }
        if ($res->serverError()) {
            return 'HTTP '.$res->status();
        }
        if (str_contains($res->body(), 'There has been a critical error on this website')) {
            return 'WordPress reported a critical error';
        }

        return null;
    }

    /**
     * WP-010: a staging copy. A second app runs the same image on the same server and
     * database, with its own table prefix, a password and noindex; once it is deployed
     * it is filled from the live site (afterDeployment).
     */
    public function createStaging(WpSite $site, ?string $userId): WpSite
    {
        if ($site->production_id) {
            throw ApiException::conflict('This is already a staging site.');
        }
        if (WpSite::allTenants()->where('production_id', $site->id)->exists()) {
            throw ApiException::conflict('This site already has a staging copy.');
        }
        $app = Application::allTenants()->findOrFail($site->application_id);
        $env = EnvVar::allTenants()->where('application_id', $app->id)->get();
        if (! $env->firstWhere('key', 'WORDPRESS_DB_HOST')) {
            throw ApiException::conflict('Staging needs the site\'s database settings in its environment (WORDPRESS_DB_HOST and the other WORDPRESS_DB_ variables).');
        }
        if ($this->prefixOf($app) === self::STAGING_PREFIX) {
            throw ApiException::conflict('The site already uses the table prefix '.self::STAGING_PREFIX.'.');
        }
        $name = substr($app->name, 0, 50).'-staging';
        if (Application::allTenants()->withTrashed()->where('name', $name)->exists()) {
            throw ApiException::conflict("An app named {$name} already exists.");
        }
        $password = Str::password(16, symbols: false);
        $custom = Domain::allTenants()->where('application_id', $app->id)->where('platform_subdomain', false)->where('name', 'not like', '*%')->orderBy('created_at')->value('name');
        $host = $custom ? 'staging.'.preg_replace('/^www\./', '', $custom) : $name.'.'.config('platform.domain');

        return DB::transaction(function () use ($site, $app, $env, $name, $password, $host, $custom, $userId) {
            $staging = Application::allTenants()->create([
                'organization_id' => $app->organization_id, 'project_id' => $app->project_id, 'server_id' => $app->server_id,
                'name' => $name, 'kind' => $app->kind, 'source_type' => $app->source_type, 'source' => $app->source, 'image' => $app->image,
                'strategy' => 'recreate', 'environment' => 'staging', 'region' => $app->region, 'size' => $app->size, 'port' => $app->port,
                'volume_path' => $app->volume_path, 'status' => 'deploying',
            ]);
            foreach ($env as $var) {
                if (! in_array($var->key, ['WORDPRESS_TABLE_PREFIX', 'WORDPRESS_CONFIG_EXTRA'], true)) {
                    EnvVar::allTenants()->create(['organization_id' => $staging->organization_id, 'application_id' => $staging->id, 'key' => $var->key, 'value' => $var->value, 'secret' => $var->secret, 'secret_id' => $var->secret_id]);
                }
            }
            $extra = (string) $env->firstWhere('key', 'WORDPRESS_CONFIG_EXTRA')?->value;
            EnvVar::allTenants()->create(['organization_id' => $staging->organization_id, 'application_id' => $staging->id, 'key' => 'WORDPRESS_TABLE_PREFIX', 'value' => self::STAGING_PREFIX, 'secret' => false]);
            EnvVar::allTenants()->create(['organization_id' => $staging->organization_id, 'application_id' => $staging->id, 'key' => 'WORDPRESS_CONFIG_EXTRA', 'value' => trim($extra."\n".self::stagingGuard($password)), 'secret' => true]);
            app(DomainService::class)->create(['name' => $host, 'application_id' => $staging->id, 'target_port' => $staging->port, 'platform_subdomain' => ! $custom], ! $custom);

            $row = WpSite::allTenants()->create([
                'organization_id' => $site->organization_id, 'application_id' => $staging->id, 'server_id' => $site->server_id,
                'url' => 'https://'.$host, 'staging' => true, 'production_id' => $site->id, 'staging_status' => 'provisioning', 'staging_password' => $password,
            ]);
            $this->audit->log('wordpress.staging.create', 'wp_sites', $site->id, ['staging' => $row->id, 'host' => $host]);
            app(DeploymentService::class)->start($staging, $userId ? User::find($userId) : null, 'manual');

            return $row;
        });
    }

    /** Called after every successful deployment: fills a newly provisioned staging site. */
    public function afterDeployment(Application $app): void
    {
        $staging = WpSite::allTenants()->where('application_id', $app->id)->where('staging_status', 'provisioning')->first();
        if ($staging) {
            $this->refreshStaging($staging, null);
        }
    }

    /** WP-010: copy the live database and wp-content into the staging site. */
    public function refreshStaging(WpSite $staging, ?string $userId): Job
    {
        [$live, $liveApp, $stagingApp] = $this->stagingPair($staging);
        $liveVol = 'app-'.$liveApp->name.'-data';
        $stgVol = 'app-'.$stagingApp->name.'-data';
        $steps = [
            ['wp.copy_tables', ['container' => $this->containerOf($liveApp), 'from' => $this->prefixOf($liveApp), 'to' => self::STAGING_PREFIX]],
            ['fs.mkdir', ['volume' => $liveVol, 'path' => 'wp-content/ws-restore', 'exist_ok' => true]],
            ['fs.trash', ['volume' => $stgVol, 'paths' => ['wp-content']]],
            ['fs.copy', ['volume' => $liveVol, 'paths' => ['wp-content'], 'dest' => '.', 'dest_volume' => $stgVol]],
            ['fs.delete', ['volume' => $stgVol, 'path' => 'wp-content/ws-restore', 'recursive' => true]],
        ];
        if ($live->url && $live->url !== $staging->url) {
            $steps[] = ['wp.cli', ['args' => ['search-replace', $live->url, $staging->url, '--skip-columns=guid', '--report-changed-only'], 'url' => '']];
        }
        $steps[] = ['wp.cli', ['args' => ['option', 'update', 'blog_public', '0']]];
        $steps[] = ['local', ['op' => 'staging', 'site' => $staging->id, 'status' => 'ready']];
        $steps[] = ['wp.inventory', []];
        $staging->update(['staging_status' => 'cloning']);
        $this->audit->log('wordpress.staging.refresh', 'wp_sites', $live->id, ['staging' => $staging->id]);

        return $this->run($staging, 'staging_refresh', $steps, [], $userId);
    }

    /**
     * WP-010: push the staging site to live. The live site gets a restore point first;
     * its old wp-content goes to the file manager's Trash, and its restore dumps are kept.
     */
    public function pushStaging(WpSite $staging, ?string $userId): Job
    {
        [$live, $liveApp, $stagingApp] = $this->stagingPair($staging);
        if ($staging->staging_status !== 'ready') {
            throw ApiException::conflict('The staging site is not ready.');
        }
        $liveVol = 'app-'.$liveApp->name.'-data';
        $stgVol = 'app-'.$stagingApp->name.'-data';
        $point = $this->newRestorePoint($live, 'push', $userId);
        $steps = array_merge($this->restorePointSteps($live, $point), [
            ['wp.copy_tables', ['container' => $this->containerOf($stagingApp), 'from' => self::STAGING_PREFIX, 'to' => $this->prefixOf($liveApp)]],
            ['fs.move', ['volume' => $liveVol, 'paths' => ['wp-content/ws-restore'], 'dest' => '.']],
            ['fs.trash', ['volume' => $liveVol, 'paths' => ['wp-content']]],
            ['fs.mkdir', ['volume' => $stgVol, 'path' => 'wp-content/ws-restore', 'exist_ok' => true]],
            ['fs.copy', ['volume' => $stgVol, 'paths' => ['wp-content'], 'dest' => '.', 'dest_volume' => $liveVol]],
            ['fs.delete', ['volume' => $liveVol, 'path' => 'wp-content/ws-restore', 'recursive' => true]],
            ['fs.move', ['volume' => $liveVol, 'paths' => ['ws-restore'], 'dest' => 'wp-content']],
        ]);
        if ($live->url && $staging->url && $live->url !== $staging->url) {
            $steps[] = ['wp.cli', ['args' => ['search-replace', $staging->url, $live->url, '--skip-columns=guid', '--report-changed-only'], 'url' => '']];
        }
        $steps[] = ['wp.cli', ['args' => ['option', 'update', 'blog_public', ($live->settings['indexing'] ?? true) ? '1' : '0']]];
        $steps[] = ['wp.inventory', []];
        $this->audit->log('wordpress.staging.push', 'wp_sites', $live->id, ['staging' => $staging->id, 'restore_point' => $point->id]);

        return $this->run($live, 'staging_push', $steps, ['restore_point_id' => $point->id, 'staging_id' => $staging->id], $userId);
    }

    /** WP-010: remove the staging app and its tables. */
    public function deleteStaging(WpSite $staging, ?string $userId): Job
    {
        [$live, , $stagingApp] = $this->stagingPair($staging);
        $job = $this->run($staging, 'staging_delete', [
            ['wp.drop_tables', ['container' => $this->containerOf($stagingApp), 'from' => self::STAGING_PREFIX]],
            ['local', ['op' => 'staging', 'site' => $staging->id, 'status' => 'deleted']],
        ], [], $userId);
        $this->audit->log('wordpress.staging.delete', 'wp_sites', $live->id, ['staging' => $staging->id]);

        return $job;
    }

    /** Basic auth and noindex for the staging site, run from wp-config (WORDPRESS_CONFIG_EXTRA). */
    public static function stagingGuard(string $password): string
    {
        $hash = hash('sha256', $password);

        return "if (PHP_SAPI !== 'cli') { header('X-Robots-Tag: noindex, nofollow');"
            ." if (! (isset(\$_SERVER['PHP_AUTH_USER']) && \$_SERVER['PHP_AUTH_USER'] === 'staging' && hash_equals('{$hash}', hash('sha256', (string) (\$_SERVER['PHP_AUTH_PW'] ?? ''))))) {"
            ." header('WWW-Authenticate: Basic realm=\"Staging\"'); http_response_code(401); exit('This staging site needs a password.'); } }";
    }

    /** @return array{WpSite, Application, Application} */
    private function stagingPair(WpSite $staging): array
    {
        $live = WpSite::allTenants()->find($staging->production_id) ?? throw ApiException::conflict('This is not a staging site.');
        $liveApp = Application::allTenants()->findOrFail($live->application_id);
        $stagingApp = Application::allTenants()->find($staging->application_id) ?? throw ApiException::conflict('The staging app no longer exists.');

        return [$live, $liveApp, $stagingApp];
    }

    private function prefixOf(Application $app): string
    {
        $prefix = (string) EnvVar::allTenants()->where('application_id', $app->id)->where('key', 'WORDPRESS_TABLE_PREFIX')->first()?->value;

        return $prefix !== '' ? $prefix : 'wp_';
    }

    private function containerOf(Application $app): string
    {
        return Container::allTenants()->where('application_id', $app->id)->where('status', 'running')->value('name') ?? $app->containerName();
    }

    public function runCron(): int
    {
        $n = 0;
        foreach (WpSite::allTenants()->get() as $site) {
            if (($site->settings['platform_cron'] ?? false) && Server::allTenants()->find($site->server_id)?->isOnline()) {
                $this->run($site, 'cron', [['wp.cli', ['args' => ['cron', 'event', 'run', '--due-now']]]], [], null);
                $n++;
            }
        }

        return $n;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $site = WpSite::allTenants()->find($job->payload['site_id'] ?? null);
        if ($command->status !== 'succeeded' || ! $site) {
            if ($site && $command->type === 'wp.inventory') {
                $site->update(['inventory_error' => (string) $command->message]);
            }
            $point = $site ? WpRestorePoint::allTenants()->find($job->payload['restore_point_id'] ?? null) : null;
            if (($job->payload['action'] ?? null) === 'update' && $point?->status === 'ready' && ! isset($job->payload['health_error'])) {
                // An update step failed after the restore point was taken: roll back.
                $touched = collect($job->payload['steps'])->firstWhere(fn ($s) => ($s[1]['op'] ?? null) === 'health')[1]['touched'];
                $done = array_slice($job->payload['steps'], 0, (int) $job->payload['step'] + 1);
                $job->update(['payload' => array_merge($job->payload, [
                    'steps' => array_merge($done, $this->rollbackSteps($site, $point, $touched), [['wp.inventory', []]]),
                    'health_error' => 'an update command failed: '.($command->message ?: 'unknown error'),
                ])]);
                $this->advance($site, $job->fresh());

                return;
            }
            if (($job->payload['action'] ?? null) === 'staging_refresh') {
                $site?->update(['staging_status' => 'failed']);
            }
            $this->jobs->fail($job, (string) ($command->message ?: 'The WordPress command failed.'), 'execute');

            return;
        }
        if ($command->type === 'wp.inventory') {
            $this->saveInventory($site, (array) $command->output);
        }
        $this->advance($site, $job, $command->output['output'] ?? null);
    }

    /** Runs the job's next step, or finishes it. */
    private function advance(WpSite $site, Job $job, mixed $output = null): void
    {
        $steps = $job->payload['steps'];
        $next = (int) $job->payload['step'] + 1;
        if ($next < count($steps)) {
            $job->update(['payload' => ['step' => $next] + $job->payload]);
            $this->dispatchStep($site, $job, $steps[$next]);

            return;
        }
        if (($job->payload['action'] ?? null) === 'setting') {
            $site->update(['settings' => array_merge($site->settings ?? [], [$job->payload['setting'] => (bool) $job->payload['on']])]);
        }
        if (($job->payload['action'] ?? null) === 'hardening') {
            $site->update(['settings' => array_merge($site->settings ?? [], ['hardening' => $job->payload['hardening']])]);
        }
        if ($error = $job->payload['health_error'] ?? null) {
            $this->jobs->fail($job, 'The update was rolled back to the restore point because '.$error.'.', 'execute');

            return;
        }
        $this->jobs->succeed($job, ['output' => $output]);
    }

    private function saveInventory(WpSite $site, array $inv): void
    {
        $plugins = array_map(fn ($p) => array_intersect_key((array) $p, array_flip(['name', 'title', 'status', 'version', 'update', 'update_version', 'auto_update'])), (array) ($inv['plugins'] ?? []));
        $themes = array_map(fn ($t) => array_intersect_key((array) $t, array_flip(['name', 'title', 'status', 'version', 'update', 'update_version'])), (array) ($inv['themes'] ?? []));
        $coreUpdate = collect((array) ($inv['core_updates'] ?? []))->pluck('version')->first();
        $updates = collect($plugins)->merge($themes)->where('update', 'available')->count() + ($coreUpdate ? 1 : 0);
        $flag = fn ($v) => in_array(strtolower(trim((string) $v)), ['1', 'true'], true);
        $site->update([
            'version' => $inv['version'] ?? null, 'php_version' => $inv['cli']['php_version'] ?? null, 'core_update' => $coreUpdate,
            'plugins' => array_values($plugins), 'themes' => array_values($themes), 'updates_count' => $updates,
            'url' => $inv['siteurl'] ?? $site->url, 'inventory_error' => null, 'inventoried_at' => now(),
            'settings' => array_merge($site->settings ?? [], [
                'maintenance' => (bool) ($inv['maintenance'] ?? false), 'debug' => $flag($inv['wp_debug'] ?? false),
                'indexing' => ($inv['blog_public'] ?? '1') !== '0', 'auto_minor' => ($inv['auto_core'] ?? '') === 'minor',
                'platform_cron' => $flag($inv['disable_cron'] ?? false),
            ]),
        ]);
        $this->scanVulnerabilities($site->fresh());
    }

    /**
     * WP-008: known vulnerabilities from the WPVulnerability database (free, no key) for the
     * installed core, plugin and theme versions, and a 0–100 score with what lowers it.
     */
    public function scanVulnerabilities(WpSite $site): void
    {
        $found = [];
        $check = function (string $kind, string $slug, ?string $version) use (&$found) {
            if (! $version) {
                return;
            }
            try {
                $url = $kind === 'core' ? "https://www.wpvulnerability.net/core/{$version}/" : "https://www.wpvulnerability.net/{$kind}/{$slug}/";
                $vulns = Http::timeout(15)->get($url)->json('data.vulnerability') ?? [];
            } catch (\Throwable) {
                return;
            }
            foreach ($vulns as $v) {
                if ($kind === 'core' || self::affects($version, (array) ($v['operator'] ?? []))) {
                    $found[] = ['type' => $kind, 'slug' => $slug, 'version' => $version, 'name' => (string) ($v['name'] ?? 'Vulnerability'),
                        'fixed_in' => ($v['operator']['max_operator'] ?? null) === 'lt' ? ($v['operator']['max_version'] ?? null) : null];
                }
            }
        };
        $check('core', 'wordpress', $site->version);
        foreach ($site->plugins ?? [] as $p) {
            $check('plugin', $p['name'], $p['version'] ?? null);
        }
        foreach ($site->themes ?? [] as $t) {
            $check('theme', $t['name'], $t['version'] ?? null);
        }
        $score = 100 - min(75, 25 * count($found)) - min(15, 5 * (int) $site->updates_count)
            - ($site->php_version && version_compare($site->php_version, '8.1', '<') ? 10 : 0);
        $site->update(['vulnerabilities' => $found, 'security_count' => count($found), 'score' => max(0, $score), 'scanned_at' => now()]);
    }

    /** Whether a version falls inside a WPVulnerability "operator" range. */
    public static function affects(string $version, array $op): bool
    {
        $min = $op['min_version'] ?? null;
        $max = $op['max_version'] ?? null;
        if ($min && ! version_compare($version, $min, ($op['min_operator'] ?? 'ge') === 'gt' ? '>' : '>=')) {
            return false;
        }
        if ($max && ! version_compare($version, $max, ($op['max_operator'] ?? 'le') === 'lt' ? '<' : '<=')) {
            return false;
        }

        return (bool) ($min || $max);
    }

    private function run(WpSite $site, string $action, array $steps, array $extra, ?string $userId, ?array $first = null): Job
    {
        $server = Server::allTenants()->find($site->server_id);
        if (! $server || ! $server->isOnline()) {
            throw ApiException::conflict('The site\'s server is offline.');
        }
        $job = $this->jobs->create('wordpress', ['execute'], $site, ['site_id' => $site->id, 'action' => $action, 'steps' => $steps, 'step' => 0] + $extra, $userId, $site->organization_id);
        $this->dispatchStep($site, $job, $first ?? $steps[0]);

        return $job;
    }

    private function dispatchStep(WpSite $site, Job $job, array $step): void
    {
        [$type, $payload] = $step;
        if ($type === 'local') {
            $this->localStep($site, $job, $payload);

            return;
        }
        if (str_starts_with($type, 'wp.')) {
            $app = Application::allTenants()->find($site->application_id);
            $container = Container::allTenants()->where('application_id', $app->id)->where('status', 'running')->value('name') ?? $app->containerName();
            $payload += ['container' => $container, 'url' => $site->url ?? ''];
        }
        $this->bus->dispatch(Server::allTenants()->find($site->server_id), $type, $payload, $job);
    }
}
