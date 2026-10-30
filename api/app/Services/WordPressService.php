<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Container;
use App\Models\Job;
use App\Models\Server;
use App\Models\WpSite;
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
            $this->jobs->fail($job, (string) ($command->message ?: 'The WordPress command failed.'), 'execute');

            return;
        }
        if ($command->type === 'wp.inventory') {
            $this->saveInventory($site, (array) $command->output);
        }
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
        $this->jobs->succeed($job, ['output' => $command->output['output'] ?? null]);
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
        if (str_starts_with($type, 'wp.')) {
            $app = Application::allTenants()->find($site->application_id);
            $container = Container::allTenants()->where('application_id', $app->id)->where('status', 'running')->value('name') ?? $app->containerName();
            $payload += ['container' => $container, 'url' => $site->url ?? ''];
        }
        $this->bus->dispatch(Server::allTenants()->find($site->server_id), $type, $payload, $job);
    }
}
