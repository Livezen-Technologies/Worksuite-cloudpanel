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
        $r = $this->render($m, $name, $data['domain'] ?? null, (array) ($data['variables'] ?? []));
        if ($r['unresolved']) {
            throw ApiException::validation(['variables' => ['Fill in: '.implode(', ', $r['unresolved'])]]);
        }

        return DB::transaction(function () use ($t, $m, $data, $name, $r, $user, $db) {
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
                $type = 'application';
                $id = $app->id;
            }

            return TemplateInstall::create([
                'template_id' => $t->id, 'version' => $t->version, 'name' => $name,
                'resource_type' => $type, 'resource_id' => $id, 'values' => $r['values'], 'user_id' => $user?->id,
            ]);
        });
    }

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
