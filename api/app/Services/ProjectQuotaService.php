<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Volume;

/**
 * PWS-D: quotas at project level (cpu, ram, storage, apps, containers, replicas) and per container
 * (cpu, ram). A missing or null key means no limit. Checked on app create/assign, deploy, scale,
 * database and volume create; a refusal is a 402 quota_exceeded naming the limit.
 */
class ProjectQuotaService
{
    public const PROJECT_KEYS = ['cpu', 'ram_mb', 'storage_gb', 'apps', 'containers', 'replicas'];

    public const CONTAINER_KEYS = ['container_cpu', 'container_ram_mb'];

    public const LABELS = [
        'cpu' => 'CPU cores', 'ram_mb' => 'memory (MB)', 'storage_gb' => 'storage (GB)', 'apps' => 'applications',
        'containers' => 'containers', 'replicas' => 'replicas', 'container_cpu' => 'CPU per container', 'container_ram_mb' => 'memory per container (MB)',
    ];

    public function limits(Project $project): array
    {
        $q = (array) ($project->quotas ?? []);

        return collect(array_merge(self::PROJECT_KEYS, self::CONTAINER_KEYS))->mapWithKeys(fn ($k) => [$k => isset($q[$k]) ? (float) $q[$k] : null])->all();
    }

    /** What one copy of the app asks for. */
    public function appShape(Application $app): array
    {
        $plan = $app->size ? Plan::where('code', $app->size)->first() : null;

        return [
            'cpu' => (float) ($app->cpu_limit ?? $plan?->vcpu ?? 0),
            'ram_mb' => (float) ($app->memory_limit_mb ?? $plan?->ram_mb ?? 0),
            'storage_gb' => (float) ($plan?->storage_gb ?? 0),
            'replicas' => max(1, (int) ($app->replicas ?: 1)),
        ];
    }

    public function usage(Project $project, ?string $exceptAppId = null): array
    {
        $apps = Application::allTenants()->where('project_id', $project->id)->when($exceptAppId, fn ($q) => $q->where('id', '!=', $exceptAppId))->get();
        $u = ['cpu' => 0.0, 'ram_mb' => 0.0, 'storage_gb' => 0.0, 'apps' => $apps->count(), 'replicas' => 0];
        foreach ($apps as $app) {
            $s = $this->appShape($app);
            $u['cpu'] += $s['cpu'] * $s['replicas'];
            $u['ram_mb'] += $s['ram_mb'] * $s['replicas'];
            $u['storage_gb'] += $s['storage_gb'];
            $u['replicas'] += $s['replicas'];
        }
        $u['storage_gb'] += (float) Volume::allTenants()->where('project_id', $project->id)->get()->sum(fn ($v) => $v->quota_gb ?? $v->size_gb ?? 0);
        $appIds = Application::allTenants()->where('project_id', $project->id)->pluck('id');
        $u['containers'] = Container::allTenants()->whereNotIn('state', ['removing', 'removed'])
            ->where(fn ($q) => $q->where('project_id', $project->id)->orWhereIn('application_id', $appIds))->count();

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $u);
    }

    /** Usage, limits and percentage for the Resources and Settings screens. */
    public function report(Project $project): array
    {
        $usage = $this->usage($project);
        $limits = $this->limits($project);

        return collect(array_merge(self::PROJECT_KEYS, self::CONTAINER_KEYS))->map(fn ($k) => [
            'key' => $k, 'label' => self::LABELS[$k], 'used' => $usage[$k] ?? null, 'limit' => $limits[$k],
            'pct' => isset($usage[$k]) && $limits[$k] ? round($usage[$k] / $limits[$k] * 100, 1) : null,
        ])->values()->all();
    }

    /**
     * Would the project stay within its quotas if $app took $shape (cpu, ram_mb, storage_gb, replicas
     * per copy), with $extra added (containers, storage_gb, apps)? Throws a 402 naming the first limit hit.
     */
    public function assert(Project $project, ?Application $app = null, array $extra = [], ?int $replicas = null): void
    {
        $limits = $this->limits($project);
        if (! array_filter($limits, fn ($v) => $v !== null)) {
            return;
        }
        $usage = $this->usage($project);
        $after = $app ? $this->usage($project, $app->id) : $usage;
        if ($app) {
            $s = $this->appShape($app);
            $n = $replicas ?? $s['replicas'];
            $after['apps']++;
            $after['cpu'] += $s['cpu'] * $n;
            $after['ram_mb'] += $s['ram_mb'] * $n;
            $after['storage_gb'] += $s['storage_gb'];
            $after['replicas'] += $n;
            $after['containers'] += max(0, $n - Container::allTenants()->where('application_id', $app->id)->whereNotIn('state', ['removing', 'removed'])->count());
            foreach (['container_cpu' => 'cpu', 'container_ram_mb' => 'ram_mb'] as $limitKey => $shapeKey) {
                if ($limits[$limitKey] !== null && $s[$shapeKey] > $limits[$limitKey]) {
                    $this->fail($project, $limitKey, $s[$shapeKey], $limits[$limitKey], $app->name);
                }
            }
        }
        foreach ($extra as $k => $v) {
            $after[$k] = ($after[$k] ?? 0) + $v;
        }
        foreach (self::PROJECT_KEYS as $k) {
            if ($limits[$k] !== null && $after[$k] > $limits[$k] && $after[$k] > $usage[$k]) {
                $this->fail($project, $k, $after[$k], $limits[$k]);
            }
        }
    }

    private function fail(Project $project, string $key, float|int $requested, float $max, ?string $app = null): never
    {
        $what = self::LABELS[$key];
        $msg = $app
            ? "{$app} asks for {$requested} {$what}, above project {$project->name}'s limit of {$max}."
            : "This would bring project {$project->name} to {$requested} {$what}, above its limit of {$max}.";

        throw ApiException::quota($msg, ['limit' => $key, 'requested' => $requested, 'max' => $max, 'project_id' => $project->id]);
    }
}
