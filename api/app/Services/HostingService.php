<?php

namespace App\Services;

use App\Contracts\SourceValidator;
use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\EnvVar;
use App\Models\Metric;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Customer "host a Docker app" flow (UHOST-001..007) and app views (UAPP/UDET). */
class HostingService
{
    public function __construct(private SourceValidator $validator, private BillingService $billing, private DeploymentService $deployments, private DomainService $domains) {}

    /** UHOST-002: image reachability / credentials, Git reachability, Compose validity, templates. */
    public function validateSource(array $source): array
    {
        return match ($source['type']) {
            'image' => $this->validator->image($source['image'] ?? '', $source['registry_auth'] ?? null),
            'git' => $this->validator->git($source['repo'] ?? '', $source['branch'] ?? 'main'),
            'compose' => (function () use ($source) {
                $r = app(ComposeService::class)->validate($source['compose'] ?? '');

                return ['ok' => $r['valid'], 'message' => $r['valid'] ? 'Compose file is valid.' : implode(' ', array_column($r['errors'], 'message')), 'services' => $r['services']];
            })(),
            'template' => ($t = self::template((string) ($source['template'] ?? '')))
                ? ['ok' => true, 'message' => 'Template available.', 'template' => $t]
                : ['ok' => false, 'message' => 'Unknown template.'],
        };
    }

    public function host(User $user, array $input): array
    {
        $source = $input['source'];
        $rendered = null;
        if ($source['type'] === 'template') {
            $template = self::template((string) $source['template']) ?? throw ApiException::validation(['source.template' => ['Unknown template.']]);
            $source['image'] = $template['image'];
            $input['port'] ??= $template['port'];
            $input['volume_path'] ??= $template['volume_path'];
            $input['health_path'] ??= $template['health_path'] ?? null;
            // TPL-3: catalogue templates generate their secrets and fill in the app's address.
            if ($model = \App\Models\Template::find($source['template'])) {
                $rendered = app(TemplateService::class)->render($model->manifest, $input['name'], $input['name'].'.'.config('platform.domain'), (array) ($source['variables'] ?? []));
                if ($rendered['unresolved']) {
                    throw ApiException::validation(['source.variables' => ['Fill in: '.implode(', ', $rendered['unresolved'])]]);
                }
                $given = collect($input['env'] ?? [])->pluck('key')->all();
                $secretKeys = app(TemplateService::class)->generatedKeys($model->manifest);
                $secretVars = collect($model->manifest['variables'] ?? [])->where('secret', true)->pluck('key')->all();
                foreach ($rendered['env'] as $k => $v) {
                    if (in_array($k, $given, true)) {
                        continue;
                    }
                    $template = (string) ($model->manifest['env'][$k] ?? '');
                    $generated = (bool) array_filter($secretKeys, fn ($g) => str_contains($template, '${'.$g.'}'));
                    $input['env'][] = ['key' => $k, 'value' => $v, 'secret' => $generated || in_array($k, $secretVars, true)];
                }
                $rendered['template'] = $model;
            }
            unset($source['variables']); // never stored on the app; secrets live in env vars and the encrypted install record
        }
        if (Application::withTrashed()->where('name', $input['name'])->exists()) {
            throw ApiException::validation(['name' => ['You already have an app with this name.']]);
        }
        $check = $this->validateSource($source);
        if (! $check['ok']) {
            throw ApiException::validation(['source' => [$check['message']]], $check['message']);
        }
        $this->billing->assertQuota($input['size']);

        $subdomain = $input['name'].'.'.config('platform.domain');
        $this->domains->assertAvailable($subdomain, null, true);

        return DB::transaction(function () use ($user, $input, $source, $check, $subdomain, $rendered) {
            $image = $source['type'] === 'git'
                ? 'registry.'.config('platform.domain').'/'.\App\Support\Tenant::current()->slug.'/'.$input['name'].':'.substr($check['commit'] ?? 'latest', 0, 12)
                : ($source['image'] ?? null);
            $app = Application::create([
                'name' => $input['name'], 'kind' => 'customer',
                'source_type' => $source['type'], 'source' => array_merge($source, ['digest' => $check['digest'] ?? null]),
                'image' => $image, 'strategy' => 'recreate', 'environment' => 'production',
                'region' => $input['region'] ?? null, 'size' => $input['size'], 'port' => $input['port'] ?? 80,
                'volume_path' => $input['volume_path'] ?? null, 'status' => 'deploying', 'health_path' => $input['health_path'] ?? null,
            ]);
            foreach ($input['env'] ?? [] as $var) {
                EnvVar::create(['application_id' => $app->id, 'key' => $var['key'], 'value' => (string) ($var['value'] ?? ''), 'secret' => (bool) ($var['secret'] ?? false)]);
            }
            if ($rendered) {
                \App\Models\TemplateInstall::create(['template_id' => $rendered['template']->id, 'version' => $rendered['template']->version, 'name' => $app->name,
                    'resource_type' => 'application', 'resource_id' => $app->id, 'values' => $rendered['values'], 'user_id' => $user->id]);
            }
            // UHOST-005: free subdomain with HTTPS on first deploy.
            $this->domains->create(['name' => $subdomain, 'application_id' => $app->id, 'target_port' => $app->port, 'platform_subdomain' => true], true);
            $deployment = $this->deployments->start($app, $user, 'manual');

            return ['app' => $this->toApi($app->fresh()), 'job_id' => $deployment->job_id, 'deployment_id' => $deployment->id];
        });
    }

    /**
     * TPL: a template customers can host: a single-image catalogue entry (not marked
     * `customer: false`), or one of the older built-in entries.
     */
    public static function template(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        if (! \App\Models\Template::query()->exists()) {
            app(TemplateService::class)->sync();
        }
        $t = \App\Models\Template::find($id);
        if ($t && $t->type === 'image' && ($t->manifest['customer'] ?? true) !== false) {
            $m = $t->manifest;

            return ['image' => $m['image'], 'port' => $m['port'] ?? 80, 'volume_path' => $m['volume'] ?? null, 'health_path' => $m['health'] ?? null,
                'size' => $m['size'] ?? 'small', 'name' => $t->name, 'description' => $m['description'] ?? ''];
        }

        return config('platform.app_templates')[$id] ?? null;
    }

    public function toApi(Application $app, bool $detail = false): array
    {
        $container = Container::where('application_id', $app->id)->orderByDesc('updated_at')->first();
        $domains = Domain::where('application_id', $app->id)->get();
        $primary = $domains->firstWhere('platform_subdomain', false) ?? $domains->first();
        $plan = \App\Models\Plan::where('code', $app->size)->first();
        $state = match (true) {
            in_array($app->status, ['deploying', 'failed', 'deleted'], true) => $app->status,
            $container?->state === 'running' => 'running',
            (bool) $container => $container->state === 'exited' ? 'stopped' : $container->state,
            default => $app->status,
        };
        $data = [
            'id' => $app->id,
            'name' => $app->name,
            'image' => $app->image,
            'source' => $app->source_summary,
            'state' => $state,
            'status' => $app->status,
            'primary_domain' => $primary?->name,
            'address' => 'https://'.$app->name.'.'.config('platform.domain'),
            'cpu_pct' => $container?->cpu_pct,
            'ram_mb' => $container?->mem_mb,
            'ram_limit_mb' => $plan?->ram_mb,
            'storage_gb' => $plan?->storage_gb,
            'size' => $app->size,
            'region' => $app->region,
            'port' => $app->port,
            'volume_path' => $app->volume_path,
            'health_path' => $app->health_path ?: '/',
            'auto_deploy' => $app->auto_deploy,
            'auto_restart' => $app->auto_restart,
            'last_deploy_at' => $app->last_deployed_at?->toIso8601ZuluString(),
            'created_at' => $app->created_at?->toIso8601ZuluString(),
        ];
        if ($detail) {
            $last = Deployment::where('application_id', $app->id)->latest()->first();
            $data += [
                'env' => EnvVar::where('application_id', $app->id)->orderBy('key')->get()->map(fn ($v) => ['key' => $v->key, 'value' => $v->secret ? null : $v->value, 'secret' => $v->secret])->values(),
                'domains' => $domains->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'https_status' => $d->https_status, 'dns_status' => $d->dns_status]),
                'addresses' => $domains->map(fn ($d) => 'https://'.$d->name)->values(),
                'last_deployment' => $last ? ['id' => $last->id, 'status' => $last->status, 'job_id' => $last->job_id, 'logs_tail' => $last->logs_tail] : null,
                // Traefik request counter via Prometheus when METRICS_DRIVER=prometheus; null otherwise.
                'requests_24h' => \App\Support\PrometheusMetrics::enabled() ? rescue(fn () => app(\App\Support\PrometheusMetrics::class)->requests24h($app->name), null, report: false) : null,
            ];
        }

        return $data;
    }

    public function metrics(Application $app, string $range): array
    {
        $container = Container::where('application_id', $app->id)->first();
        if (\App\Support\PrometheusMetrics::enabled() && $container) {
            $prom = app(\App\Support\PrometheusMetrics::class);
            $step = MetricsService::RANGES[$range][1] ?? 300;
            $q = \App\Support\PrometheusMetrics::containerQueries($container->name);

            return ['range' => $range, 'cpu' => $prom->range($q['cpu'], MetricsService::since($range), now(), $step), 'ram' => $prom->range($q['ram'], MetricsService::since($range), now(), $step), 'source' => 'prometheus'];
        }
        $points = $container ? Metric::where('container_id', $container->id)->where('recorded_at', '>=', MetricsService::since($range))->orderBy('recorded_at')->get() : collect();

        return [
            'range' => $range,
            'cpu' => $points->map(fn ($p) => ['t' => $p->recorded_at->toIso8601ZuluString(), 'v' => $p->cpu_pct])->values(),
            'ram' => $points->map(fn ($p) => ['t' => $p->recorded_at->toIso8601ZuluString(), 'v' => $p->mem_mb])->values(),
        ];
    }
}
