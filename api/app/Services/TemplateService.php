<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\ComposeProject;
use App\Models\EnvVar;
use App\Models\Template;
use App\Models\TemplateInstall;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * One-click templates (TPL-1..6). Manifests live in api/templates (images.yaml
 * and compose/*.yaml) and, optionally, a remote JSON index
 * (TEMPLATES_REMOTE_URL); `templates:sync` loads both.
 *
 * Placeholders, rendered once per install and kept for updates:
 *   ${SERVICE_PASSWORD_X}  32 random letters and digits
 *   ${SERVICE_USER_X}      a random user name
 *   ${SERVICE_HEX_X}       64 hex characters
 *   ${SERVICE_BASE64_X}    32 random bytes, base64
 *   ${SERVICE_FQDN}        the install's domain (or <name>.localhost)
 *   ${SERVICE_URL}         https://<domain>, or http://<name>.localhost
 *   ${STACK}               the install name
 *   ${KEY}                 a template variable (user input or its default)
 */
class TemplateService
{
    public const TYPES = ['image', 'compose'];

    private const PLACEHOLDER = '/\$\{(SERVICE_(?:PASSWORD|USER|HEX|BASE64)_[A-Z0-9_]+|SERVICE_FQDN|SERVICE_URL|STACK|[A-Z][A-Z0-9_]*)\}/';

    public function __construct(private ComposeService $compose) {}

    /** @return list<array> every bundled manifest, normalised */
    public function bundled(): array
    {
        $dir = base_path('templates');
        $out = array_map(fn ($m) => $m + ['type' => 'image'], (array) Yaml::parseFile($dir.'/images.yaml'));
        // TPL: our stacks, then services imported from Coolify's catalogue (Apache-2.0, templates/coolify).
        foreach (array_merge(glob($dir.'/compose/*.yaml') ?: [], glob($dir.'/coolify/*.yaml') ?: []) as $file) {
            $out[] = Yaml::parseFile($file) + ['type' => 'compose'];
        }

        return $out;
    }

    /** TPL-4: upsert bundled and remote manifests; a remote entry wins only with a higher version. */
    public function sync(): array
    {
        $stats = ['bundled' => 0, 'remote' => 0, 'invalid' => []];
        $apply = function (array $m, string $source) use (&$stats) {
            if ($errors = $this->lint($m)['errors']) {
                $stats['invalid'][($m['id'] ?? '?')] = $errors;

                return;
            }
            $current = Template::find($m['id']);
            if ($current && $source === 'remote' && $current->version >= (int) $m['version']) {
                return;
            }
            Template::updateOrCreate(['id' => $m['id']], [
                'name' => $m['name'], 'category' => $m['category'] ?? 'Other', 'type' => $m['type'],
                'version' => (int) $m['version'], 'manifest' => $m, 'source' => $source, 'synced_at' => now(),
            ]);
            $stats[$source]++;
        };
        foreach ($this->bundled() as $m) {
            $apply($m, 'bundled');
        }
        if ($url = config('platform.templates.remote_url')) {
            try {
                foreach ((array) Http::timeout(15)->get($url)->throw()->json('templates', []) as $m) {
                    if (is_array($m)) {
                        $apply($m + ['type' => 'image'], 'remote');
                    }
                }
            } catch (\Throwable $e) {
                $stats['remote_error'] = $e->getMessage();
            }
        }

        return $stats;
    }

    public function catalogue(): \Illuminate\Support\Collection
    {
        if (! Template::query()->exists()) {
            $this->sync();
        }

        return Template::orderBy('category')->orderBy('name')->get();
    }

    public function present(Template $t): array
    {
        $m = $t->manifest;

        return [
            'id' => $t->id, 'name' => $t->name, 'category' => $t->category, 'type' => $t->type, 'version' => $t->version,
            'description' => $m['description'] ?? '', 'image' => $m['image'] ?? null, 'port' => $m['port'] ?? null,
            'accent' => $m['accent'] ?? null, 'source' => $t->source,
            'services' => $t->type === 'compose' ? array_keys((array) (Yaml::parse($m['compose'])['services'] ?? [])) : null,
            'variables' => array_values(array_map(fn ($v) => ['key' => $v['key'], 'label' => $v['label'] ?? $v['key'], 'default' => $v['default'] ?? null, 'required' => (bool) ($v['required'] ?? false), 'secret' => (bool) ($v['secret'] ?? false)], (array) ($m['variables'] ?? []))),
            'generated' => $this->generatedKeys($m),
        ];
    }

    /** Placeholder names a manifest generates (passwords, users, keys). */
    public function generatedKeys(array $m): array
    {
        preg_match_all('/\$\{(SERVICE_(?:PASSWORD|USER|HEX|BASE64)_[A-Z0-9_]+)\}/', json_encode($m['env'] ?? []).($m['compose'] ?? ''), $mm);

        return array_values(array_unique($mm[1]));
    }

    public static function generate(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'SERVICE_PASSWORD_') => Str::password(32, symbols: false),
            str_starts_with($key, 'SERVICE_USER_') => 'u'.strtolower(Str::random(9)),
            str_starts_with($key, 'SERVICE_HEX_') => bin2hex(random_bytes(32)),
            default => base64_encode(random_bytes(32)),
        };
    }

    /**
     * @param  array  $values  previous values (kept on update) — generated secrets and variables
     * @return array{values: array, env: array<string,string>, compose: ?string, unresolved: list<string>}
     */
    public function render(array $m, string $name, ?string $domain, array $input, array $values = []): array
    {
        foreach ($this->generatedKeys($m) as $key) {
            $values[$key] ??= self::generate($key);
        }
        foreach ((array) ($m['variables'] ?? []) as $v) {
            $given = $input[$v['key']] ?? null;
            if ($given !== null && $given !== '') {
                $values[$v['key']] = (string) $given;
            } else {
                $values[$v['key']] ??= isset($v['default']) ? (string) $v['default'] : null;
            }
        }
        $fqdn = $domain ?: $name.'.localhost';
        $builtins = ['SERVICE_FQDN' => $fqdn, 'SERVICE_URL' => ($domain ? 'https://' : 'http://').$fqdn, 'STACK' => $name];
        $unresolved = [];
        $sub = function (string $s) use ($values, $builtins, &$unresolved, $m) {
            $declared = array_column((array) ($m['variables'] ?? []), 'key');

            return preg_replace_callback(self::PLACEHOLDER, function ($x) use ($values, $builtins, &$unresolved, $declared) {
                $k = $x[1];
                if (isset($builtins[$k])) {
                    return $builtins[$k];
                }
                if (array_key_exists($k, $values) && $values[$k] !== null) {
                    return $values[$k];
                }
                if (str_starts_with($k, 'SERVICE_') || in_array($k, $declared, true)) {
                    $unresolved[] = $k;
                }

                return $x[0]; // not ours: leave Compose's own ${VAR} interpolation alone
            }, $s);
        };
        $env = [];
        foreach ((array) ($m['env'] ?? []) as $k => $v) {
            $env[$k] = $sub((string) $v);
        }
        // Variables without an env mapping become env vars of the same name (image templates).
        foreach ((array) ($m['variables'] ?? []) as $v) {
            if (($m['type'] ?? 'image') === 'image' && ! array_key_exists($v['key'], (array) ($m['env'] ?? [])) && isset($values[$v['key']])) {
                $env[$v['key']] = $values[$v['key']];
            }
        }
        $compose = isset($m['compose']) ? $sub((string) $m['compose']) : null;
        foreach ((array) ($m['variables'] ?? []) as $v) {
            if (! empty($v['required']) && ($values[$v['key']] ?? '') === '') {
                $unresolved[] = $v['key'];
            }
        }

        return ['values' => $values, 'env' => $env, 'compose' => $compose, 'unresolved' => array_values(array_unique($unresolved))];
    }

    /** TPL-6: checks one manifest the way an install would use it. */
    public function lint(array $m): array
    {
        $errors = [];
        $warnings = [];
        foreach (['id', 'name', 'version', 'type'] as $f) {
            if (empty($m[$f])) {
                $errors[] = "missing {$f}";
            }
        }
        if (! empty($m['id']) && ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', (string) $m['id'])) {
            $errors[] = 'id must be lowercase letters, digits and dashes';
        }
        if (! in_array($m['type'] ?? null, self::TYPES, true)) {
            $errors[] = 'type must be image or compose';
        }
        if (($m['type'] ?? null) === 'image') {
            if (empty($m['image']) || preg_match('/\s/', (string) $m['image'])) {
                $errors[] = 'image is required';
            } elseif (! str_contains(substr((string) $m['image'], strrpos((string) $m['image'], '/') ?: 0), ':') || str_ends_with((string) $m['image'], ':latest')) {
                $warnings[] = 'image tag is not pinned';
            }
            if (isset($m['port']) && ((int) $m['port'] < 1 || (int) $m['port'] > 65535)) {
                $errors[] = 'port out of range';
            }
        }
        if (($m['type'] ?? null) === 'compose' && empty($m['compose'])) {
            $errors[] = 'compose is required';
        }
        if (! $errors) {
            $dummy = [];
            foreach ((array) ($m['variables'] ?? []) as $v) {
                if (empty($v['key']) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $v['key'])) {
                    $errors[] = 'variable without a valid key';

                    continue;
                }
                $dummy[$v['key']] = 'test';
            }
            $r = $this->render($m, 'test', 'test.example.com', $dummy);
            if ($r['unresolved']) {
                $errors[] = 'unresolved placeholders: '.implode(', ', $r['unresolved']);
            }
            if ($r['compose'] !== null) {
                $check = $this->compose->validate($r['compose']);
                foreach ($check['errors'] as $e) {
                    $errors[] = 'compose: '.$e['message'];
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function install(Template $t, array $data, ?User $user): TemplateInstall
    {
        $m = $t->manifest;
        $name = $data['name'];
        // INST-3: an existing managed database fills the template's connection variables on the server,
        // so its password never reaches the browser; the app goes on the database's server and network.
        $db = null;
        if (! empty($data['database_id']) && $t->type !== 'compose') {
            $db = \App\Models\Database::findOrFail($data['database_id']);
            $data['server_id'] = $db->server_id;
            $data['variables'] = $this->databaseVariables($m, $db, (array) ($data['variables'] ?? []));
        }
        // INST-6: the public address comes from the chosen domain mode (auto, subdomain, custom, none).
        [$host, $platformHost] = $this->resolveHost($data, $name);
        if ($host && $t->type !== 'compose' && empty($data['port'] ?? $m['port'] ?? null)) {
            throw ApiException::validation(['port' => ['A public address needs the port the app listens on.']]);
        }
        $r = $this->render($m, $name, $host, (array) ($data['variables'] ?? []));
        if ($r['unresolved']) {
            throw ApiException::validation(['variables' => ['Fill in: '.implode(', ', $r['unresolved'])]]);
        }

        $install = DB::transaction(function () use ($t, $m, $data, $name, $r, $user, $db, $host, $platformHost) {
            if ($t->type === 'compose') {
                if (empty($data['server_id'])) {
                    throw ApiException::validation(['server_id' => ['Choose a server for the stack.']]);
                }
                if (ComposeProject::where('name', $name)->exists()) {
                    throw ApiException::validation(['name' => ['A stack with this name already exists.']]);
                }
                $project = ComposeProject::create(['name' => $name, 'server_id' => $data['server_id'], 'environment' => $data['environment'] ?? 'production', 'source' => 'editor']);
                $this->compose->addRevision($project, $r['compose']);
                $this->compose->deploy($project);
                $type = 'compose_project';
                $id = $project->id;
            } else {
                if (Application::where('name', $name)->exists()) {
                    throw ApiException::validation(['name' => ['An application with this name already exists.']]);
                }
                $secretKeys = array_merge($this->generatedKeys($m), array_column(array_filter((array) ($m['variables'] ?? []), fn ($v) => ! empty($v['secret'])), 'key'));
                $app = Application::create(array_filter([
                    'name' => $name, 'kind' => 'admin', 'source_type' => 'image', 'image' => $m['image'], 'port' => $data['port'] ?? $m['port'] ?? null,
                    'health_path' => $data['health_path'] ?? $m['health'] ?? null, 'volume_path' => $data['volume_path'] ?? $m['volume'] ?? null, 'server_id' => $data['server_id'] ?? null,
                    'environment' => $data['environment'] ?? 'production', 'source' => array_filter(['template' => $t->id, 'database_id' => $db?->id]),
                    'size' => $data['size'] ?? null,
                ], fn ($v) => $v !== null) + (array_key_exists('auto_restart', $data) ? ['auto_restart' => (bool) $data['auto_restart']] : []));
                foreach ($r['env'] as $k => $v) {
                    $secret = (bool) array_filter($secretKeys, fn ($s) => str_contains((string) ($m['env'][$k] ?? '${'.$k.'}'), '${'.$s.'}'))
                        || ($db && preg_match('/PASS/i', $k));
                    EnvVar::create(['application_id' => $app->id, 'key' => $k, 'value' => $v, 'secret' => $secret]);
                }
                foreach ((array) ($data['env'] ?? []) as $e) {
                    if (! isset($r['env'][$e['key']])) {
                        EnvVar::create(['application_id' => $app->id, 'key' => $e['key'], 'value' => (string) ($e['value'] ?? ''), 'secret' => (bool) ($e['secret'] ?? false)]);
                    }
                }
                if ($host) {
                    app(DomainService::class)->create(['name' => $host, 'application_id' => $app->id, 'target_port' => $app->port, 'platform_subdomain' => $platformHost], $platformHost);
                }
                $type = 'application';
                $id = $app->id;
            }

            return TemplateInstall::create([
                'template_id' => $t->id, 'version' => $t->version, 'name' => $name,
                'resource_type' => $type, 'resource_id' => $id, 'values' => $r['values'], 'user_id' => $user?->id,
            ]);
        });
        // The first deployment starts right away; the app reads Running only after its health check passes.
        if ($install->resource_type === 'application') {
            $deployment = app(DeploymentService::class)->start(Application::findOrFail($install->resource_id), $user, 'manual');
            $install->setAttribute('job_id', $deployment->job_id);
            $install->setAttribute('deployment_id', $deployment->id);
        }
        $install->setAttribute('url', $host ? 'https://'.$host : null);

        return $install;
    }

    /** INST-6: [hostname or null, is it under the platform domain]. */
    public function resolveHost(array $data, string $name): array
    {
        $platform = (string) config('platform.domain');
        $mode = $data['domain_mode'] ?? (! empty($data['domain']) ? 'custom' : 'none');

        return match ($mode) {
            'auto' => [$this->uniqueSubdomain($name), true],
            'subdomain' => [$this->platformSubdomain((string) ($data['subdomain'] ?? '')), true],
            'custom' => [$this->customDomain((string) ($data['domain'] ?? '')), false],
            default => [null, false],
        };
    }

    /** A free, DNS-safe name such as wordpress-k3x9q2.<platform domain>. */
    public function uniqueSubdomain(string $name): string
    {
        $base = trim(substr(preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), 0, 40), '-') ?: 'app';
        for ($i = 0; $i < 10; $i++) {
            $host = $base.'-'.strtolower(Str::random(6)).'.'.config('platform.domain');
            $host = preg_replace('/[^a-z0-9.-]/', 'x', $host);
            if (! \App\Models\Domain::allTenants()->where('name', $host)->exists()) {
                return $host;
            }
        }
        throw ApiException::conflict('Could not find a free subdomain; try again.');
    }

    private function platformSubdomain(string $label): string
    {
        $label = strtolower(trim($label));
        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            throw ApiException::validation(['subdomain' => ['Use lowercase letters, numbers and dashes (not at the start or end).']]);
        }
        $host = $label.'.'.config('platform.domain');
        if (\App\Models\Domain::allTenants()->where('name', $host)->exists()) {
            throw ApiException::validation(['subdomain' => ["{$host} is taken. Pick another name."]]);
        }

        return $host;
    }

    private function customDomain(string $domain): string
    {
        $domain = strtolower(trim($domain, ". \t"));
        if (! preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw ApiException::validation(['domain' => ['Enter a domain such as shop.example.com.']]);
        }
        try {
            app(DomainService::class)->assertAvailable($domain);
        } catch (ApiException $e) {
            throw ApiException::validation(['domain' => [$e->getMessage()]]);
        }

        return $domain;
    }

    /**
     * INST-7: everything the wizard must know before it lets someone install. Each check is
     * pass, warn (installs, but read this) or fail (blocks the install), tagged with its wizard step.
     */
    public function preflight(Template $t, array $data): array
    {
        $m = $t->manifest;
        $checks = [];
        $add = function (string $key, string $step, string $status, string $label, string $message) use (&$checks) {
            $checks[] = compact('key', 'step', 'status', 'label', 'message');
        };
        $compose = $t->type === 'compose';
        $name = (string) ($data['name'] ?? '');

        // Details
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name) || strlen($name) > 63) {
            $add('name', 'details', 'fail', 'Name', 'Use lowercase letters, numbers and dashes, up to 63 characters.');
        } elseif ($compose ? ComposeProject::where('name', $name)->exists() : Application::withTrashed()->where('name', $name)->exists()) {
            $add('name', 'details', 'fail', 'Name', "“{$name}” is already used. Pick another name.");
        } else {
            $add('name', 'details', 'pass', 'Name', "“{$name}” is available.");
        }
        if (! $compose) {
            $add('image', 'details', empty($m['image']) ? 'fail' : 'pass', 'Docker image', empty($m['image']) ? 'The template has no image.' : (string) $m['image']);
        }

        // Server
        $db = ! $compose && ! empty($data['database_id']) ? \App\Models\Database::find($data['database_id']) : null;
        $serverId = $db?->server_id ?? ($data['server_id'] ?? null);
        $server = $serverId ? \App\Models\Server::find($serverId) : null;
        if ($serverId && ! $server) {
            $add('server', 'server', 'fail', 'Server', 'The chosen server no longer exists.');
        } elseif (! $server) {
            $online = \App\Models\Server::all()->filter(fn ($s) => $s->isOnline() && $s->hasRole('app'));
            $compose
                ? $add('server', 'server', 'fail', 'Server', 'Choose a server for the stack.')
                : $add('server', 'server', $online->count() ? 'pass' : 'fail', 'Server', $online->count() ? "Placed automatically on one of {$online->count()} online server(s)." : 'No server is online to place the app on.');
        } else {
            $add('server', 'server', $server->isOnline() ? 'pass' : 'fail', 'Server', $server->isOnline() ? "{$server->name} is {$server->status}." : "{$server->name} is {$server->status}; it must be online.");
            $add('docker', 'server', $server->docker_error ? 'fail' : ($server->docker_version ? 'pass' : 'warn'), 'Docker',
                $server->docker_error ? 'Docker reports: '.$server->docker_error : ($server->docker_version ? 'Docker '.$server->docker_version.' is running.' : 'Docker has not reported a version yet.'));
            if (! $compose && $server->ram_mb) {
                $need = self::SIZE_RAM[$data['size'] ?? 'standard'] ?? 1024;
                $free = (int) round($server->ram_mb * (1 - ((float) ($server->ram_pct ?? 0)) / 100));
                $add('memory', 'server', $free >= $need ? 'pass' : 'warn', 'Memory', $free >= $need ? "{$free} MB free for the {$need} MB this size needs." : "Only {$free} MB free; this size needs {$need} MB. It may be slow or be stopped.");
            }
            if ($server->disk_pct !== null) {
                $add('disk', 'server', $server->disk_pct > 90 ? 'fail' : ($server->disk_pct > 80 ? 'warn' : 'pass'), 'Disk', round($server->disk_pct).'% of the disk is used.');
            }
        }
        $port = (int) ($data['port'] ?? $m['port'] ?? 0);
        if (! $compose && $port && ($port < 1 || $port > 65535)) {
            $add('port', 'server', 'fail', 'Port', 'The port must be between 1 and 65535.');
        }

        // Domain
        try {
            [$host, $platformHost] = $this->resolveHost($data, $name ?: 'app');
            if (($data['domain_mode'] ?? null) === 'auto') {
                $host = '<name>-xxxxxx.'.config('platform.domain');
            }
            if (! $host) {
                $add('domain', 'details', 'pass', 'Domain', 'No public address; the app is reachable only inside its network.');
            } elseif (! $compose && ! $port) {
                $add('domain', 'details', 'fail', 'Domain', 'A public address needs the port the app listens on.');
            } elseif ($platformHost) {
                $add('domain', 'details', 'pass', 'Domain', "{$host} is free; DNS and HTTPS are handled for you.");
            } else {
                $zone = \App\Models\DnsZone::forDomain($host, \App\Support\Tenant::current()?->id);
                $add('domain', 'details', $zone ? 'pass' : 'warn', 'Domain', $zone
                    ? "{$host} is in your {$zone->name} DNS zone; its record is created automatically."
                    : "{$host} is free. Point its A record at the server; HTTPS is issued once DNS resolves.");
            }
        } catch (ApiException $e) {
            $msg = collect($e->fields ?? [])->flatten()->first() ?? $e->getMessage();
            $add('domain', 'details', 'fail', 'Domain', (string) $msg);
        }
        if (! \App\Models\Server::all()->contains(fn ($s) => $s->isOnline()) ) {
            $add('proxy', 'details', 'warn', 'Proxy', 'No server is online to route traffic yet.');
        }

        // Database
        if (! empty($data['database_id']) && ! $compose) {
            if (! $db) {
                $add('database', 'database', 'fail', 'Database', 'The chosen database no longer exists.');
            } else {
                $ok = in_array($db->status, ['running', 'healthy', 'active', null], true);
                $add('database', 'database', $ok ? 'pass' : 'fail', 'Database', $ok
                    ? "{$db->name} ({$db->engine}) is {$db->status}; credentials are filled in from its stored secret."
                    : "{$db->name} is {$db->status}; start it before installing.");
                $add('database_network', 'database', 'pass', 'Connectivity', 'The app joins the private network that reaches db-'.Str::slug($db->name).'.');
            }
        }
        $vars = (array) ($data['variables'] ?? []);
        if ($db) {
            $vars = $this->databaseVariables($m, $db, $vars);
        }
        $missing = collect((array) ($m['variables'] ?? []))->filter(fn ($v) => ! empty($v['required']) && trim((string) ($vars[$v['key']] ?? $v['default'] ?? '')) === '')->pluck('label')->all();
        $add('variables', 'database', $missing ? 'fail' : 'pass', 'Configuration', $missing ? 'Fill in: '.implode(', ', $missing) : 'All required settings are filled in.');

        // Advanced
        foreach ((array) ($data['env'] ?? []) as $e) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) ($e['key'] ?? ''))) {
                $add('env', 'advanced', 'fail', 'Environment', '“'.($e['key'] ?? '').'” is not a valid variable name.');
            }
        }

        $failed = collect($checks)->where('status', 'fail')->count();

        return ['ok' => $failed === 0, 'platform_domain' => (string) config('platform.domain'), 'failed' => $failed, 'warnings' => collect($checks)->where('status', 'warn')->count(), 'checks' => $checks];
    }

    private const SIZE_RAM = ['small' => 512, 'standard' => 1024, 'pro' => 4096];

    /** INST-3: fill a template's database variables (host, port, user, password) from a managed database. */
    public function databaseVariables(array $m, \App\Models\Database $db, array $vars): array
    {
        $tpl = config('platform.database_templates')[$db->engine] ?? [];
        $host = 'db-'.\Illuminate\Support\Str::slug($db->name);
        $port = (string) ($tpl['port'] ?? '');
        $user = $db->engine === 'postgres' ? 'postgres' : 'root';
        $password = (string) (\App\Models\Secret::find($db->secret_id)?->value ?? '');
        $keys = array_column((array) ($m['variables'] ?? []), 'key');
        $hasPort = (bool) preg_grep('/(DB|DATABASE|MYSQL|MARIADB|POSTGRES|PG).*PORT$/', $keys);
        // A template with a single, generically named host variable (phpMyAdmin's PMA_HOST) points at the database too.
        $hosts = preg_grep('/HOST$/', $keys);
        $loneHost = count($hosts) === 1 && ! preg_grep('/(DB|DATABASE|MYSQL|MARIADB|POSTGRES|PG).*HOST$/', $keys) ? reset($hosts) : null;
        foreach ($keys as $k) {
            if ($k !== $loneHost && ! preg_match('/(DB|DATABASE|MYSQL|MARIADB|POSTGRES|PG)/', $k)) {
                continue;
            }
            $vars[$k] = match (true) {
                (bool) preg_match('/HOST$/', $k) => $hasPort || $port === '' ? $host : "{$host}:{$port}",
                (bool) preg_match('/PORT$/', $k) => $port,
                (bool) preg_match('/(USER|USERNAME)$/', $k) => $user,
                (bool) preg_match('/(PASSWORD|PASS)$/', $k) => $password,
                default => $vars[$k] ?? '',
            };
        }

        return $vars;
    }

    /** TPL-5: re-render with the stored values on the template's newest version and redeploy. */
    public function update(TemplateInstall $install, ?User $user): TemplateInstall
    {
        $t = Template::findOrFail($install->template_id);
        if ($t->version <= $install->version) {
            throw ApiException::conflict('This install is already on the latest template version.');
        }
        $m = $t->manifest;
        if ($install->resource_type === 'compose_project') {
            $project = ComposeProject::findOrFail($install->resource_id);
            $domain = null;
            $r = $this->render($m, $install->name, $domain, [], $install->values ?? []);
            $this->compose->addRevision($project, $r['compose']);
            $this->compose->deploy($project);
        } else {
            $app = Application::findOrFail($install->resource_id);
            $r = $this->render($m, $install->name, null, [], $install->values ?? []);
            $app->update(['image' => $m['image'], 'port' => $m['port'] ?? $app->port, 'health_path' => $m['health'] ?? $app->health_path]);
            $existing = EnvVar::where('application_id', $app->id)->pluck('key')->all();
            foreach ($r['env'] as $k => $v) {
                if (! in_array($k, $existing, true)) {
                    EnvVar::create(['application_id' => $app->id, 'key' => $k, 'value' => $v, 'secret' => str_starts_with((string) ($m['env'][$k] ?? ''), '${SERVICE_')]);
                }
            }
            app(DeploymentService::class)->start($app, $user, 'template_update');
        }
        $install->update(['version' => $t->version, 'values' => $r['values']]);

        return $install;
    }

    public function installs(): array
    {
        $latest = Template::pluck('version', 'id');

        return TemplateInstall::latest()->get()->map(fn ($i) => [
            'id' => $i->id, 'template_id' => $i->template_id, 'name' => $i->name, 'version' => $i->version,
            'latest_version' => $latest[$i->template_id] ?? null, 'update_available' => ($latest[$i->template_id] ?? 0) > $i->version,
            'resource_type' => $i->resource_type, 'resource_id' => $i->resource_id, 'created_at' => $i->created_at,
        ])->values()->all();
    }
}
