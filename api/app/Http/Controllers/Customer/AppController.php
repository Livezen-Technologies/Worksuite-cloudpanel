<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\EnvVar;
use App\Models\Release;
use App\Services\BillingService;
use App\Services\ContainerService;
use App\Services\DeploymentService;
use App\Services\HostingService;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppController extends Controller
{
    public function __construct(private HostingService $hosting, private DeploymentService $deployments) {}

    private function sourceRules(): array
    {
        return [
            'source' => 'required|array',
            'source.type' => 'required|in:image,git,compose,template',
            'source.image' => 'required_if:source.type,image|nullable|string|max:255|regex:/^[a-z0-9][a-z0-9._\/:@-]*$/i',
            'source.registry_auth' => 'nullable|array', 'source.registry_auth.username' => 'nullable|string', 'source.registry_auth.password' => 'nullable|string',
            'source.repo' => 'required_if:source.type,git|nullable|string|max:255',
            'source.branch' => 'nullable|string|max:120',
            'source.dockerfile_path' => ['nullable', 'string', 'max:255', 'not_regex:#(^/|\.\.)#'],
            'source.builder' => 'nullable|in:dockerfile,auto', 'source.no_cache' => 'nullable|boolean',
            'source.compose' => 'required_if:source.type,compose|nullable|string|max:200000',
            'source.template' => ['required_if:source.type,template', 'nullable', 'string', 'max:80', fn ($attr, $v, $fail) => $v && ! HostingService::template((string) $v) ? $fail('Unknown template.') : null],
            'source.variables' => 'nullable|array', 'source.variables.*' => 'nullable|string|max:4096',
        ];
    }

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Application::where('kind', 'customer'), $request, ['name', 'image'], ['name', 'created_at', 'last_deployed_at'], ['status'], fn ($a) => $this->hosting->toApi($a)));
    }

    public function validateSource(Request $request)
    {
        $data = $request->validate($this->sourceRules());

        return $this->data($this->hosting->validateSource($data['source']));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->sourceRules() + [
            'name' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'port' => 'nullable|integer|between:1,65535',
            'region' => 'nullable|string|max:60',
            'env' => 'nullable|array', 'env.*.key' => 'required|string|max:120|regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'env.*.value' => 'nullable|string|max:10000', 'env.*.secret' => 'nullable|boolean',
            'volume_path' => 'nullable|string|max:255|starts_with:/',
            'size' => 'required|in:small,standard,pro',
            'health_path' => ['nullable', 'string', 'max:255', 'regex:#^/[^\s]*$#'],
            // UHOST-007: customers can never request these.
            'privileged' => 'prohibited', 'network_mode' => 'prohibited', 'host_network' => 'prohibited',
        ], ['name.regex' => 'Use lowercase letters, numbers and hyphens (it becomes part of your web address).']);

        $result = $this->hosting->host($request->user(), $data);

        return response()->json(['data' => $result], 202);
    }

    public function show(string $id)
    {
        return $this->data($this->hosting->toApi($this->find($id), true));
    }

    public function update(Request $request, string $id, BillingService $billing, ContainerService $containers)
    {
        $app = $this->find($id);
        $data = $request->validate([
            'env' => 'sometimes|array', 'env.*.key' => 'required|string|max:120|regex:/^[A-Za-z_][A-Za-z0-9_]*$/', 'env.*.value' => 'nullable|string|max:10000', 'env.*.secret' => 'nullable|boolean',
            'size' => 'sometimes|in:small,standard,pro',
            'auto_deploy' => 'sometimes|boolean',
            'auto_restart' => 'sometimes|boolean',
            'restart' => 'sometimes|boolean',
            'health_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^/[^\s]*$#'],
        ]);
        if (isset($data['size']) && $data['size'] !== $app->size) {
            $billing->assertQuota($data['size'], $app);
        }
        $app->update(collect($data)->only(['size', 'auto_deploy', 'auto_restart', 'health_path'])->all());
        if (array_key_exists('env', $data)) {
            $keys = collect($data['env'])->pluck('key');
            EnvVar::where('application_id', $app->id)->whereNotIn('key', $keys)->delete();
            foreach ($data['env'] as $var) {
                $existing = EnvVar::where('application_id', $app->id)->where('key', $var['key'])->first();
                if ($existing && $existing->secret && ($var['value'] ?? null) === null) {
                    continue; // masked secret left unchanged
                }
                EnvVar::updateOrCreate(['application_id' => $app->id, 'key' => $var['key']], ['value' => (string) ($var['value'] ?? ''), 'secret' => (bool) ($var['secret'] ?? false)]);
            }
        }
        $jobId = null;
        // UDET-003 "Save & restart": env/size changes need a redeploy to apply.
        if (($data['restart'] ?? false) || isset($data['size']) || array_key_exists('env', $data)) {
            $jobId = $this->deployments->start($app->fresh(), $request->user(), 'redeploy')->job_id;
        }

        return $this->data($this->hosting->toApi($app->fresh(), true) + ['job_id' => $jobId]);
    }

    public function destroy(string $id)
    {
        $app = $this->find($id);
        // UDET-005: data kept 7 days, then purged.
        $app->retire();

        return response()->noContent();
    }

    public function restart(string $id, ContainerService $containers)
    {
        $app = $this->find($id);
        $container = Container::where('application_id', $app->id)->firstOrFail();

        return $this->accepted($containers->perform($container, 'restart'));
    }

    public function redeploy(Request $request, string $id)
    {
        $deployment = $this->deployments->start($this->find($id), $request->user(), 'redeploy');

        return $this->accepted($deployment->job, ['deployment_id' => $deployment->id]);
    }

    public function rollback(Request $request, string $id)
    {
        $app = $this->find($id);
        $data = $request->validate(['release_id' => 'required|uuid']);
        $release = Release::where('application_id', $app->id)->findOrFail($data['release_id']);
        $deployment = $this->deployments->start($app, $request->user(), 'rollback', $release);

        return $this->accepted($deployment->job, ['deployment_id' => $deployment->id]);
    }

    public function logs(Request $request, string $id, ContainerService $containers)
    {
        $app = $this->find($id);
        $container = Container::where('application_id', $app->id)->first();
        $last = Deployment::where('application_id', $app->id)->latest()->first();
        if (! $container) {
            return $this->data(['lines' => $last?->logs_tail ?? [], 'fetched_at' => null, 'job_id' => null]);
        }

        return $this->data($containers->logs($container, max(1, min(2000, (int) $request->query('tail', 200)))));
    }

    public function releases(string $id)
    {
        $app = $this->find($id);

        return $this->data(Release::where('application_id', $app->id)->orderByDesc('version')->get()->map(fn (Release $r) => [
            'id' => $r->id, 'version' => $r->version, 'image' => $r->image, 'status' => $r->status, 'current' => $r->id === $app->current_release_id,
            'created_at' => $r->created_at?->toIso8601ZuluString(),
        ]));
    }

    public function metrics(Request $request, string $id)
    {
        return $this->data($this->hosting->metrics($this->find($id), (string) $request->query('range', '24h')));
    }

    private function find(string $id): Application
    {
        return Application::where('kind', 'customer')->findOrFail($id);
    }
}
