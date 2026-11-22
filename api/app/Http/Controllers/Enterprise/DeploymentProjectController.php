<?php

namespace App\Http\Controllers\Enterprise;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\ProjectDomainController;
use App\Http\Controllers\ProjectBackupController;
use App\Http\Controllers\ProjectDashboardController;
use App\Http\Controllers\ProjectFirewallController;
use App\Http\Controllers\ProjectResourceController;
use App\Http\Controllers\ProjectWorkspaceController;
use App\Models\Alert;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\BackupArtifact;
use App\Models\BackupJob;
use App\Models\Domain;
use App\Models\EnterpriseDeployment;
use App\Models\EnvVar;
use App\Models\FirewallEvent;
use App\Models\Metric;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\BackupService;
use App\Services\BlockedIpService;
use App\Services\DnsValidationService;
use App\Services\EnterpriseProvisioningService;
use App\Services\MetricsService;
use App\Services\ProjectDashboardService;
use App\Services\ScalingService;
use App\Services\UptimeService;
use App\Support\ProjectGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Enterprise part F (docs/ENTERPRISE_API.md): the deployment page's CloudPanel tabs for Cloud Admin.
 * Each deployment IS a panel project, so every endpoint here resolves the deployment inside the
 * tenant (the staff organisation) and then calls the ordinary project controller for that one
 * project under a ProjectGrant (platform_admin on that project only). The project endpoints keep
 * their own validation, audit entries and JSON shapes; only the composed views (security, settings,
 * monitor, resources, scale) are built here, from the same services.
 */
class DeploymentProjectController extends Controller
{
    use FindsDeployment;

    public function __construct(private EnterpriseProvisioningService $svc, private AuditLogger $audit) {}

    /** Run $fn(projectId) as platform_admin of the deployment's project. */
    private function on(EnterpriseDeployment $dep, callable $fn): mixed
    {
        if (! $dep->project_id || ! Project::whereKey($dep->project_id)->exists()) {
            throw new ApiException('project_unavailable', 'This deployment has no panel project (not created yet, or purged).', 409);
        }

        return app(ProjectGrant::class)->as($dep->project_id, 'platform_admin', fn () => $fn($dep->project_id));
    }

    /** Delegate to a project controller and pass its response through unchanged. */
    private function pass(string $ref, callable $fn): mixed
    {
        return $this->on($this->find($ref), $fn);
    }

    private static function inner(mixed $response): array
    {
        return $response instanceof JsonResponse ? (array) ($response->getData(true)['data'] ?? []) : [];
    }

    /** @return array<string, Application> role (app|worker) => application */
    private function apps(EnterpriseDeployment $dep): array
    {
        $rows = Application::allTenants()->whereIn('id', array_filter([$dep->app_application_id, $dep->worker_application_id]))->get()->keyBy('id');

        return array_filter(['app' => $rows[$dep->app_application_id] ?? null, 'worker' => $rows[$dep->worker_application_id] ?? null]);
    }

    private function app(EnterpriseDeployment $dep, string $role = 'app'): Application
    {
        return $this->apps($dep)[$role] ?? throw ApiException::conflict("The deployment has no {$role} application yet.");
    }

    private static function unsupported(string $message): ApiException
    {
        return new ApiException('PANEL_UNSUPPORTED', $message, 501);
    }

    // ---------------------------------------------------------------- Domain & DNS (Customer\ProjectDomainController)

    public function dns(Request $request, string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->index($request, $p));
    }

    public function dnsShow(Request $request, string $ref, string $domainId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->show($request, $p, $domainId));
    }

    public function dnsStore(Request $request, string $ref)
    {
        $dep = $this->find($ref);
        // The project's only public app is the web container: default app_id to it.
        if (! $request->filled('app_id') && $dep->app_application_id) {
            $request->merge(['app_id' => $dep->app_application_id]);
        }
        $request->merge(['kind' => $request->input('kind', 'custom')]);

        return $this->on($dep, fn ($p) => app(ProjectDomainController::class)->store($request, $p));
    }

    public function dnsUpdate(Request $request, string $ref, string $domainId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->update($request, $p, $domainId));
    }

    public function dnsValidate(Request $request, string $ref, string $domainId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->validateDns($request, $p, $domainId, app(DnsValidationService::class)));
    }

    public function dnsPublish(Request $request, string $ref, string $domainId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->publish($request, $p, $domainId));
    }

    public function dnsCertificate(Request $request, string $ref, string $domainId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->certificate($request, $p, $domainId));
    }

    public function dnsDestroy(Request $request, string $ref, string $domainId)
    {
        $dep = $this->find($ref);
        $name = Domain::allTenants()->whereKey($domainId)->value('name');
        if ($name !== null && $name === $dep->hostname) {
            throw ApiException::conflict('The platform address '.$dep->hostname.' stays with the deployment; it cannot be removed.');
        }

        return $this->on($dep, fn ($p) => app(ProjectDomainController::class)->destroy($request, $p, $domainId));
    }

    public function dnsHistory(Request $request, string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->history($request, $p));
    }

    public function dnsZoneRecords(Request $request, string $ref, string $domainId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectDomainController::class)->zoneRecords($request, $p, $domainId));
    }

    // ---------------------------------------------------------------- Firewall (ProjectFirewallController)

    public function firewall(Request $request, string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectFirewallController::class)->show($request, $p));
    }

    public function firewallIpStore(Request $request, string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectFirewallController::class)->storeIp($request, $p));
    }

    public function firewallIpDestroy(Request $request, string $ref, string $entryId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectFirewallController::class)->destroyIp($request, $p, $entryId));
    }

    public function firewallRuleStore(Request $request, string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectFirewallController::class)->storeRule($request, $p));
    }

    public function firewallRuleDestroy(Request $request, string $ref, string $ruleId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectFirewallController::class)->destroyRule($request, $p, $ruleId));
    }

    /** lock | unlock the web app (maintenance: only allow-listed addresses get through). */
    public function firewallLock(Request $request, string $ref, string $action)
    {
        $dep = $this->find($ref);
        $app = $this->app($dep);

        return $this->on($dep, fn ($p) => app(ProjectFirewallController::class)->lock($request, $p, $app->id, $action));
    }

    // ---------------------------------------------------------------- Security (composed)

    public function security(Request $request, string $ref)
    {
        $dep = $this->find($ref);

        return $this->on($dep, function ($p) use ($request, $dep) {
            $apps = $this->apps($dep);
            $appIds = collect($apps)->pluck('id')->all();
            $domains = Domain::allTenants()->whereIn('application_id', $appIds)->get(['id', 'name']);
            $firewall = self::inner(app(ProjectFirewallController::class)->show($request, $p));
            $projectIps = collect($firewall['block'] ?? [])->pluck('id')->all();
            $blocked = collect(rescue(fn () => app(BlockedIpService::class)->list(), [], report: true))
                ->filter(fn ($r) => $r['kind'] !== 'manual' || in_array($r['list_id'] ?? null, $projectIps, true))->values();
            $events = FirewallEvent::allTenants()->where('organization_id', $dep->organization_id)->whereIn('domain_id', $domains->pluck('id'))
                ->orderByDesc('occurred_at')->limit(50)->get()
                ->map(fn ($e) => ['id' => $e->id, 'occurred_at' => $e->occurred_at, 'action' => $e->action, 'ip' => $e->ip, 'country' => $e->country,
                    'method' => $e->method, 'path' => $e->path, 'rule' => $e->rule_name, 'domain' => $domains->firstWhere('id', $e->domain_id)?->name])->values();
            $web = $apps['app'] ?? null;

            return $this->data([
                'tls' => collect($this->svc->domainList($dep))->map(fn ($d) => ['domain' => $d['domain'], 'kind' => $d['kind'], 'primary' => $d['primary'], 'tls_status' => $d['tls_status'], 'https_raw' => $d['https_raw']])->values(),
                'access' => ['public' => $web ? $web->public_access !== false : null, 'locked' => $web ? $web->public_access === false : null,
                    'lock_reason' => $web?->lock_reason, 'locked_at' => $web?->locked_at],
                'blocked_ips' => $blocked,
                'events' => $events,
                'members' => app(ProjectWorkspaceController::class)->members(Project::findOrFail($p)),
                'member_roles' => \App\Models\ProjectMember::ROLES,
                'audit' => AuditLog::query()->withoutGlobalScopes()->where('organization_id', $dep->organization_id)
                    ->whereIn('resource_id', array_merge([$p, $dep->id], $appIds))->orderByDesc('id')->limit(50)
                    ->get(['id', 'actor', 'action', 'resource_type', 'resource_id', 'ip', 'created_at'])->values(),
            ]);
        });
    }

    public function memberSet(Request $request, string $ref, string $userId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectWorkspaceController::class)->setMember($request, $p, $userId));
    }

    public function memberRemove(Request $request, string $ref, string $userId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectWorkspaceController::class)->removeMember($request, $p, $userId));
    }

    // ---------------------------------------------------------------- Settings (env + app settings)

    /** Keys the panel owns: shown, never editable here (APP_URL follows the primary domain). */
    public static function reservedKeys(): array
    {
        return array_values(array_unique(array_merge(array_values((array) config('enterprise.env')), (array) config('enterprise.reserved_env'))));
    }

    private function presentSettings(EnterpriseDeployment $dep): array
    {
        $apps = $this->apps($dep);
        $reserved = self::reservedKeys();
        $tzKey = (string) config('enterprise.timezone_env');
        $vars = EnvVar::allTenants()->whereIn('application_id', collect($apps)->pluck('id'))->orderBy('key')->get();
        $roleOf = collect($apps)->mapWithKeys(fn ($a, $role) => [$a->id => $role]);
        $web = $apps['app'] ?? null;
        $webVar = fn (string $key) => $web ? $vars->first(fn ($v) => $v->application_id === $web->id && $v->key === $key) : null;

        return [
            'env' => $vars->map(fn (EnvVar $v) => [
                'application' => $roleOf[$v->application_id] ?? null, 'key' => $v->key,
                'value' => $v->secret ? null : $v->value, 'secret' => (bool) $v->secret,
                'read_only' => $v->secret || in_array($v->key, $reserved, true),
            ])->values(),
            'reserved_keys' => $reserved,
            'app_url' => $webVar((string) config('enterprise.env.base_url'))?->value,
            'timezone' => $webVar($tzKey)?->value,
            'timezone_key' => $tzKey,
            'maintenance' => ['enabled' => $web ? $web->public_access === false : false, 'reason' => $web?->lock_reason, 'since' => $web?->locked_at, 'until' => $web?->maintenance_until,
                'schedule' => $web ? app(\App\Services\MaintenanceScheduleService::class)->present($web) : null],
            'restart_policy' => ['value' => 'unless-stopped', 'editable' => false],
            'version' => $dep->version,
            'image' => EnterpriseProvisioningService::image($dep->version),
            'status' => $dep->status,
            'danger_zone' => ['delete' => 'DELETE /api/v1/enterprise/deployments/'.$dep->id, 'purge' => 'POST /api/v1/enterprise/deployments/'.$dep->id.'/purge'],
        ];
    }

    public function settings(string $ref)
    {
        $dep = $this->find($ref);

        return $this->on($dep, fn () => $this->data($this->presentSettings($dep)));
    }

    public function updateSettings(Request $request, string $ref)
    {
        $dep = $this->find($ref);
        $data = $request->validate([
            'env' => 'sometimes|array|max:100',
            'env.*.key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'env.*.value' => 'nullable|string|max:8000',
            'env.*.application' => 'sometimes|in:app,worker,both',
            'env.*.delete' => 'sometimes|boolean',
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
            'maintenance' => 'sometimes|boolean',
            'maintenance_reason' => 'nullable|string|max:255',
            'maintenance_until' => 'nullable|date|after:now',
            'maintenance_schedule' => 'sometimes|nullable|array',
            'version' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/'],
            'redeploy' => 'sometimes|boolean',
        ]);

        return $this->on($dep, function ($p) use ($request, $dep, $data) {
            $apps = $this->apps($dep);
            $reserved = self::reservedKeys();
            $changes = collect($data['env'] ?? []);
            if (isset($data['timezone'])) {
                $changes->push(['key' => (string) config('enterprise.timezone_env'), 'value' => $data['timezone'], 'application' => 'both', 'tz' => true]);
            }
            $errors = [];
            foreach ($changes as $i => $c) {
                if (in_array($c['key'], $reserved, true)) {
                    $errors["env.{$i}.key"] = ["{$c['key']} is managed by the panel and cannot be changed here."];
                }
                foreach ($this->targets($apps, $c['application'] ?? 'both') as $app) {
                    if (EnvVar::allTenants()->where('application_id', $app->id)->where('key', $c['key'])->where('secret', true)->exists()) {
                        $errors["env.{$i}.key"] = ["{$c['key']} is a secret; it is not editable here."];
                    }
                }
            }
            if ($errors) {
                throw ApiException::validation($errors);
            }
            $changed = [];
            foreach ($changes as $c) {
                foreach ($this->targets($apps, $c['application'] ?? 'both') as $role => $app) {
                    if (! empty($c['delete'])) {
                        EnvVar::allTenants()->where('application_id', $app->id)->where('key', $c['key'])->delete();
                    } else {
                        app(\App\Support\Tenant::class)->as($app->organization_id, fn () => EnvVar::updateOrCreate(['application_id' => $app->id, 'key' => $c['key']], ['value' => (string) ($c['value'] ?? ''), 'secret' => false]));
                    }
                    $changed[] = ['application' => $role, 'key' => $c['key'], 'deleted' => ! empty($c['delete'])];
                }
            }
            if ($changed) {
                $this->audit->log('enterprise.deployment.settings.env', 'enterprise_deployment', $dep->id, ['changes' => $changed], $dep->organization_id);
            }
            if (array_key_exists('maintenance', $data) && isset($apps['app'])) {
                $request->merge(['reason' => $data['maintenance_reason'] ?? null, 'until' => $data['maintenance_until'] ?? null]);
                app(ProjectFirewallController::class)->lock($request, $p, $apps['app']->id, $data['maintenance'] ? 'lock' : 'unlock');
            }
            if (array_key_exists('maintenance_schedule', $data) && isset($apps['app'])) {
                $window = $data['maintenance_schedule'];
                if ($window !== null) {
                    $window = validator($window, \App\Services\MaintenanceScheduleService::rules())->validate();
                    // Deployments made before 1.33.0 lack the notice URL; it reaches the app on the next redeploy.
                    if (EnterpriseProvisioningService::ensureNoticeUrl($apps['app'])) {
                        $changed[] = ['application' => 'app', 'key' => (string) config('enterprise.env.maintenance_notice_url'), 'deleted' => false];
                    }
                }
                app(\App\Services\MaintenanceScheduleService::class)->set($apps['app'], $window, $dep->organization_id);
            }
            $redeploy = null;
            if (! empty($data['redeploy']) || isset($data['version'])) {
                $redeploy = $this->svc->act($dep->fresh(), 'redeploy', array_filter(['version' => $data['version'] ?? null]));
                rescue(fn () => app(\App\Services\EnterpriseRunReportService::class)->started($dep->fresh(), 'redeploy', (array) ($redeploy['job_ids'] ?? []), $request->user(), 'Settings changed'));
            }

            return $this->data($this->presentSettings($dep->fresh()) + ['changed' => $changed, 'redeploy' => $redeploy,
                'needs_redeploy' => $changed !== [] && $redeploy === null]);
        });
    }

    /** @return array<string, Application> */
    private function targets(array $apps, string $which): array
    {
        return $which === 'both' ? $apps : array_intersect_key($apps, [$which => true]);
    }

    // ---------------------------------------------------------------- Backup (ProjectBackupController)

    public function backups(string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectBackupController::class)->index($p));
    }

    public function backupRun(Request $request, string $ref)
    {
        $dep = $this->find($ref);
        $response = $this->on($dep, fn ($p) => app(ProjectBackupController::class)->store($request, $p));
        // ENT-MAIL: one summary for the whole backup set (every application and database job).
        if ($backupId = self::inner($response)['id'] ?? null) {
            rescue(fn () => app(\App\Services\EnterpriseRunReportService::class)->started($dep, 'backup', [], $request->user(), $request->input('reason'), $backupId));
        }

        return $response;
    }

    public function backupSchedule(Request $request, string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectBackupController::class)->schedule($request, $p));
    }

    private function backupOf(string $projectId, string $backupId): BackupJob
    {
        return BackupJob::where('target_type', 'project')->where('project_id', $projectId)->whereKey($backupId)->first()
            ?? throw new ApiException('backup_not_found', 'No such backup of this deployment.', 404);
    }

    public function backupShow(string $ref, string $backupId)
    {
        return $this->pass($ref, fn ($p) => app(ProjectBackupController::class)->show($this->backupOf($p, $backupId)->id));
    }

    /** Restore: the project service always takes a fresh recovery point first; confirm is mandatory. */
    public function backupRestore(Request $request, string $ref, string $backupId)
    {
        if (! $request->has('scope')) {
            $request->merge(['scope' => 'project']);
        }

        return $this->pass($ref, fn ($p) => app(ProjectBackupController::class)->restore($request, $this->backupOf($p, $backupId)->id));
    }

    public function backupDownload(Request $request, string $ref, string $backupId, string $artifactId)
    {
        return $this->pass($ref, function ($p) use ($request, $backupId, $artifactId) {
            $run = $this->backupOf($p, $backupId);
            $artifact = BackupArtifact::allTenants()->whereKey($artifactId)
                ->whereIn('backup_job_id', BackupJob::where('parent_id', $run->id)->pluck('id'))->first()
                ?? throw new ApiException('artifact_not_found', 'No such archive in this backup.', 404);
            $out = app(BackupService::class)->download($artifact, $request->user()?->id);
            $this->audit->log('project.backup.download', 'project', $p, ['backup_id' => $run->id, 'artifact_id' => $artifact->id], $artifact->organization_id);

            return $this->data($out, $out['status'] === 'ready' ? 200 : 202);
        });
    }

    // ---------------------------------------------------------------- Monitor (composed)

    public function monitor(Request $request, string $ref)
    {
        $data = $request->validate(['range' => 'nullable|in:1h,6h,24h,7d']);
        $range = $data['range'] ?? '1h';
        $dep = $this->find($ref);

        return $this->on($dep, function () use ($dep, $range) {
            $metrics = $this->svc->metrics($dep, $range);
            $since = MetricsService::since($range);
            $network = [];
            foreach ($this->svc->containersOf($dep) as $role => $c) {
                $points = $c ? Metric::where('container_id', $c->id)->where('recorded_at', '>=', $since)->orderBy('recorded_at')->get(['net_rx', 'net_tx', 'recorded_at']) : collect();
                $network[] = ['role' => $role, 'name' => $c?->name,
                    'rx' => $points->map(fn ($m) => ['t' => $m->recorded_at->toIso8601ZuluString(), 'v' => $m->net_rx])->values(),
                    'tx' => $points->map(fn ($m) => ['t' => $m->recorded_at->toIso8601ZuluString(), 'v' => $m->net_tx])->values()];
            }
            $present = $this->svc->present($dep);
            $uptimeRange = in_array($range, ['1h', '6h', '24h'], true) ? '24h' : '7d';
            $appIds = collect($this->apps($dep))->pluck('id')->all();
            $domains = Domain::allTenants()->whereIn('application_id', $appIds)->get();
            $containerIds = collect($this->svc->containersOf($dep))->filter()->pluck('id')->all();

            return $this->data([
                'range' => $range,
                'health' => $present['health'],
                'containers' => $present['containers'],
                'series' => $metrics['series'],
                'metrics' => $metrics['containers'],
                'network' => $network,
                'disk' => $metrics['disk'],
                'uptime' => $domains->map(fn (Domain $d) => app(UptimeService::class)->report($d, $uptimeRange))->values(),
                'alerts' => Alert::allTenants()->where('organization_id', $dep->organization_id)
                    ->whereIn('resource_id', array_merge([$dep->project_id, $dep->server_id], $appIds, $containerIds, $domains->pluck('id')->all()))
                    ->orderByDesc('created_at')->limit(50)->get()
                    ->map(fn (Alert $a) => ['id' => $a->id, 'title' => $a->title, 'severity' => $a->severity, 'status' => $a->status, 'resource_type' => $a->resource_type,
                        'value' => $a->value, 'created_at' => $a->created_at?->toIso8601ZuluString(), 'resolved_at' => $a->resolved_at?->toIso8601ZuluString()])->values(),
            ]);
        });
    }

    // ---------------------------------------------------------------- Resources (ProjectDashboard + ProjectResource)

    public function resources(Request $request, string $ref)
    {
        $dep = $this->find($ref);

        return $this->on($dep, function ($p) use ($request, $dep) {
            $dashboard = self::inner(app(ProjectDashboardController::class)($request, app(ProjectDashboardService::class), $p));
            $volumes = self::inner(app(ProjectResourceController::class)->volumes($request, $p));
            $databases = self::inner(app(ProjectResourceController::class)->databases($request, $p));
            $disk = $this->svc->metrics($dep, '1h')['disk'];

            return $this->data([
                'health' => $dashboard['health'] ?? null,
                'counts' => $dashboard['counts'] ?? [],
                'applications' => $dashboard['applications'] ?? [],
                'containers' => $dashboard['containers'] ?? [],
                'databases' => $databases['databases'] ?? [],
                'volumes' => $volumes['volumes'] ?? [],
                'deployment_volumes' => $disk['volumes'],
                'disk' => ['used_mb' => $disk['used_mb'], 'limit_gb' => $disk['limit_gb']],
                'nodes' => $dashboard['nodes'] ?? [],
            ]);
        });
    }

    // ---------------------------------------------------------------- Scale up (resize + replicas + quotas)

    private function presentScale(Request $request, EnterpriseDeployment $dep, string $p): array
    {
        $web = $this->apps($dep)['app'] ?? null;

        return [
            'limits' => $this->svc->present($dep)['limits'],
            'sizes' => collect(config('enterprise.sizes'))->map(fn ($s, $k) => ['code' => $k, 'label' => $s['label'], 'cpus' => $s['app_cpus'], 'ram_mb' => $s['app_ram_mb'],
                'worker_cpus' => $s['worker_cpus'], 'worker_ram_mb' => $s['worker_ram_mb'], 'disk_gb' => $s['disk_gb'], 'fpm_max_children' => $s['fpm_max_children']])->values(),
            'custom_limits' => config('enterprise.custom_limits'),
            'replicas' => [
                'web' => ['replicas' => max(1, (int) ($web->replicas ?? 1)), 'supported' => (bool) $web, 'max' => ScalingService::MAX],
                // The worker runs the scheduler: a second copy would run every job twice.
                'worker' => ['replicas' => 1, 'supported' => false, 'reason' => 'The worker runs the WorkSuite scheduler; only one copy may run.'],
            ],
            'quotas' => self::inner(app(ProjectResourceController::class)->quotas($request, $p)),
        ];
    }

    public function scale(Request $request, string $ref)
    {
        $dep = $this->find($ref);

        return $this->on($dep, fn ($p) => $this->data($this->presentScale($request, $dep, $p)));
    }

    public function updateScale(Request $request, string $ref)
    {
        $dep = $this->find($ref);
        $b = (array) config('enterprise.custom_limits');
        $data = $request->validate([
            'size' => ['nullable', Rule::in(array_keys(config('enterprise.sizes')))],
            'cpus' => ['nullable', 'numeric', 'min:'.$b['cpus'][0], 'max:'.$b['cpus'][1]],
            'ram_mb' => ['nullable', 'integer', 'min:'.$b['ram_mb'][0], 'max:'.$b['ram_mb'][1]],
            'disk_gb' => ['nullable', 'integer', 'min:'.$b['disk_gb'][0], 'max:'.$b['disk_gb'][1]],
            'web_replicas' => 'nullable|integer|min:1|max:'.ScalingService::MAX,
            'worker_replicas' => 'nullable|integer|min:1|max:'.ScalingService::MAX,
            ...DeploymentController::packageRules(),
        ]);
        if (isset($data['worker_replicas']) && $data['worker_replicas'] !== 1) {
            throw self::unsupported('The worker runs the WorkSuite scheduler; it cannot have more than one copy.');
        }
        $limits = array_filter(array_intersect_key($data, array_flip(['size', 'package', 'cpus', 'ram_mb', 'disk_gb'])), fn ($v) => $v !== null);
        if (! $limits && ! isset($data['web_replicas']) && ! isset($data['worker_replicas'])) {
            throw ApiException::validation(['size' => ['Give a size, a package, cpus / ram_mb / disk_gb, or web_replicas.']]);
        }

        return $this->on($dep, function ($p) use ($request, $dep, $data, $limits) {
            $scaled = null;
            if (isset($data['web_replicas'])) {
                $event = app(ScalingService::class)->scale($this->app($dep), $data['web_replicas'], 'enterprise scale-up', $request->user()?->id);
                $scaled = ['from' => $event->from, 'to' => $event->to, 'result' => $event->result];
                $this->svc->step($dep, 'scale', 'succeeded', "Web copies {$event->from} → {$event->to}", 'Scale');
            }
            if ($limits) {
                $dep = $this->svc->resize($dep, $limits);
            }

            return response()->json(['data' => $this->presentScale($request, $dep->fresh(), $p) + ['replicas_change' => $scaled]], 202);
        });
    }

    public function scaleQuotas(Request $request, string $ref)
    {
        return $this->pass($ref, fn ($p) => app(ProjectResourceController::class)->setQuotas($request, $p));
    }
}
