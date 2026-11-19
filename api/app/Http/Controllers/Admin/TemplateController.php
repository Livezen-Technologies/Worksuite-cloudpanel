<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\TemplateInstall;
use App\Services\TemplateService;
use Illuminate\Http\Request;

/** TPL-1..6: the template catalogue, installs, updates and sync. */
class TemplateController extends Controller
{
    public function __construct(private TemplateService $templates) {}

    public function index(Request $request)
    {
        $q = strtolower((string) $request->query('q', ''));
        $rows = $this->templates->catalogue()
            ->filter(fn ($t) => (! $request->filled('category') || $t->category === $request->query('category'))
                && (! $request->filled('type') || $t->type === $request->query('type'))
                && ($q === '' || str_contains(strtolower($t->name.' '.$t->id.' '.($t->manifest['description'] ?? '').' '.($t->manifest['image'] ?? '')), $q)))
            ->map(fn ($t) => $this->templates->present($t))->values();

        return $this->data($rows);
    }

    public function show(string $template)
    {
        $this->templates->catalogue();

        return $this->data($this->templates->present(Template::findOrFail($template)));
    }

    public function install(Request $request, string $template)
    {
        $this->templates->catalogue();
        $tpl = Template::findOrFail($template);
        $data = $request->validate($this->installRules());
        // Install together with a new database: create the database container first (same server,
        // generated password), then the app is wired to it exactly like an existing managed database.
        if (! empty($data['create_database']['engine']) && empty($data['database_id']) && $tpl->type !== 'compose') {
            $data = $this->withNewDatabase($tpl, $data);
        }
        $install = $this->templates->install($tpl, $data, $request->user());

        return $this->data(['id' => $install->id, 'resource_type' => $install->resource_type, 'resource_id' => $install->resource_id, 'version' => $install->version,
            'job_id' => $install->job_id, 'deployment_id' => $install->deployment_id, 'url' => $install->url], 201);
    }

    private function withNewDatabase(Template $tpl, array $data): array
    {
        $serverId = $data['server_id'] ?? null;
        if (! $serverId) {
            $probe = new \App\Models\Application(['size' => $data['size'] ?? 'standard']);
            $probe->organization_id = \App\Support\Tenant::current()?->id;
            $serverId = app(\App\Services\Scheduler::class)->place($probe, app(\App\Services\Scheduler::class)->ramFor($probe))->id;
        }
        // The app's own database name: the template's DB-name variable when it has one, else the app name.
        $vars = (array) ($data['variables'] ?? []);
        $nameKey = collect(array_column((array) ($tpl->manifest['variables'] ?? []), null, 'key'))
            ->keys()->first(fn ($k) => preg_match('/(DB|DATABASE|MYSQL|MARIADB|POSTGRES|PG)_?(NAME|DATABASE|DB)$/', $k) && ! preg_match('/HOST|PORT|USER|PASS/', $k));
        $default = $nameKey ? (collect($tpl->manifest['variables'])->firstWhere('key', $nameKey)['default'] ?? null) : null;
        $dbName = preg_replace('/[^a-z0-9_]/', '_', strtolower((string) ($nameKey && ! empty($vars[$nameKey]) ? $vars[$nameKey] : ($default ?: $data['name']))));
        if ($nameKey) {
            $data['variables'][$nameKey] = $dbName;
        }
        $res = app(\App\Services\DatabaseService::class)->create([
            'name' => $data['name'].'-db', 'engine' => $data['create_database']['engine'], 'version' => $data['create_database']['version'] ?? null,
            'server_id' => $serverId, 'environment' => $data['environment'] ?? 'production', 'database' => $dbName,
            'memory_mb' => $data['create_database']['memory_mb'] ?? 512,
        ]);
        $data['database_id'] = $res['database']['id'];
        $data['server_id'] = $serverId;

        return $data;
    }

    /** INST-7: run the install checks without installing anything. */
    public function preflight(Request $request, string $template)
    {
        $this->templates->catalogue();

        return $this->data($this->templates->preflight(Template::findOrFail($template), $request->validate($this->installRules(false))));
    }

    private function installRules(bool $strict = true): array
    {
        // Preflight reports a bad name, domain or variable as a check instead of a 422.
        $loose = $strict ? [] : ['name' => 'nullable|string|max:200', 'domain' => 'nullable|string|max:253', 'env.*.key' => 'nullable|string|max:120'];

        return $loose + [
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'server_id' => 'nullable|uuid|exists:servers,id',
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'environment' => 'sometimes|in:production,staging,development',
            'project_id' => 'nullable|uuid|exists:projects,id', // install straight into a project
            'variables' => 'sometimes|array', 'variables.*' => 'nullable|string|max:4096',
            // INST-2..4: options from the install wizard (single-image apps).
            'size' => 'nullable|in:small,standard,pro',
            'port' => 'nullable|integer|between:1,65535',
            'volume_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^\s]*$#'],
            'health_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^\s]*$#'],
            'auto_restart' => 'sometimes|boolean',
            'env' => 'nullable|array|max:100', 'env.*.key' => 'required|string|max:120|regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'env.*.value' => 'nullable|string|max:4096', 'env.*.secret' => 'nullable|boolean',
            'database_id' => 'nullable|uuid',
            'create_database' => 'nullable|array',
            'create_database.engine' => 'nullable|in:postgres,mysql,mariadb,mongodb,redis',
            'create_database.version' => 'nullable|string|max:20',
            'create_database.memory_mb' => 'nullable|integer|min:128|max:65536',
            'domain_mode' => 'nullable|in:auto,subdomain,custom,none',
            'subdomain' => 'nullable|string|max:63',
        ];
    }

    public function installs()
    {
        return $this->data($this->templates->installs());
    }

    public function update(Request $request, string $id)
    {
        $install = $this->templates->update(TemplateInstall::findOrFail($id), $request->user());

        return $this->data(['id' => $install->id, 'version' => $install->version], 202);
    }

    public function sync()
    {
        return $this->data($this->templates->sync());
    }
}
