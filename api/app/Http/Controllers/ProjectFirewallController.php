<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\FirewallIpList;
use App\Models\FirewallRule;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\ProjectAccess;
use App\Services\TraefikConfigService;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * PWS-E: a project's firewall — IP allow/block lists, rate limits and app lock/unlock — stored as
 * the existing project-scoped firewall_ip_lists / firewall_rules rows and rendered by the existing
 * Traefik file-provider path (FirewallConfigService). Those rows only ever attach middlewares to
 * the panel's own dmp- routers of this project's domains, so other tenants' routers and the
 * hand-written ones are never touched. Admin and /app share it.
 */
class ProjectFirewallController extends Controller
{
    public function __construct(private ProjectAccess $access, private AuditLogger $audit, private TraefikConfigService $traefik) {}

    private function project(Request $request, string $id, string $ability): array
    {
        $project = Project::find($id) ?? (($p = Project::allTenants()->find($id)) && $this->access->role($p, $request->user()) === 'platform_admin' ? $p : null);
        abort_unless($project, 404);

        return [$project, $this->access->authorize($project, $request->user(), $ability)];
    }

    public function show(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'view');
        $ips = FirewallIpList::allTenants()->where('organization_id', $project->organization_id)->where('project_id', $project->id)->orderBy('list')->orderBy('cidr')->get();
        $rules = FirewallRule::allTenants()->where('organization_id', $project->organization_id)->where('project_id', $project->id)->orderBy('priority')->get();
        $apps = Application::allTenants()->where('project_id', $project->id)->orderBy('name')->get(['id', 'name', 'public_access', 'lock_reason', 'locked_at', 'maintenance_until']);

        return $this->data([
            'can_manage' => in_array('manage', $this->access->abilities($role), true),
            'allow' => $ips->where('list', 'allow')->values()->map(fn ($e) => $this->ip($e)),
            'block' => $ips->where('list', 'block')->values()->map(fn ($e) => $this->ip($e)),
            'rate_limits' => $rules->map(fn (FirewallRule $r) => ['id' => $r->id, 'name' => $r->name, 'paths' => $r->paths, 'limit' => $r->limit, 'window_seconds' => $r->window_seconds, 'action' => $r->action, 'enabled' => (bool) $r->enabled])->values(),
            'apps' => $apps->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'locked' => $a->public_access === false, 'lock_reason' => $a->lock_reason, 'locked_at' => $a->locked_at, 'maintenance_until' => $a->maintenance_until])->values(),
            'inherited' => [
                'ip_entries' => FirewallIpList::allTenants()->where('organization_id', $project->organization_id)->whereNull('domain_id')->whereNull('application_id')->whereNull('project_id')->count(),
                'rules' => FirewallRule::allTenants()->where('organization_id', $project->organization_id)->whereNull('domain_id')->whereNull('application_id')->whereNull('project_id')->count(),
            ],
        ]);
    }

    private function ip(FirewallIpList $e): array
    {
        return ['id' => $e->id, 'cidr' => $e->cidr, 'note' => $e->note, 'expires_at' => $e->expires_at, 'active' => ! $e->expires_at || $e->expires_at->isFuture()];
    }

    public function storeIp(Request $request, string $id)
    {
        [$project] = $this->project($request, $id, 'manage');
        $data = $request->validate(['list' => 'required|in:allow,block', 'cidr' => 'required|string|max:64', 'note' => 'nullable|string|max:255', 'expires_at' => 'nullable|date|after:now', 'confirm' => 'sometimes|boolean']);
        $cidr = FirewallIpList::normalise($data['cidr']);
        if (! $cidr) {
            throw ApiException::validation(['cidr' => ['Enter an IP address or a CIDR range, such as 203.0.113.0/24.']]);
        }
        $ip = $request->ip();
        if ($data['list'] === 'block' && ! ($data['confirm'] ?? false) && $ip && FirewallIpList::contains($cidr, $ip)) {
            throw ApiException::conflict("{$cidr} includes your own address ({$ip}). Send confirm to block it anyway.");
        }
        if (FirewallIpList::allTenants()->where('organization_id', $project->organization_id)->where('list', $data['list'])->where('cidr', $cidr)->exists()) {
            throw ApiException::conflict("{$cidr} is already on your organisation's {$data['list']} list.");
        }
        $entry = app(Tenant::class)->as($project->organization_id, fn () => FirewallIpList::create(['list' => $data['list'], 'cidr' => $cidr, 'note' => $data['note'] ?? null, 'expires_at' => $data['expires_at'] ?? null, 'project_id' => $project->id]));
        $this->audit->log('project.firewall.ip.add', 'project', $project->id, ['list' => $data['list'], 'cidr' => $cidr], $project->organization_id);
        $this->traefik->push();

        return $this->data($this->ip($entry), 201);
    }

    public function destroyIp(Request $request, string $id, string $entryId)
    {
        [$project] = $this->project($request, $id, 'manage');
        $e = FirewallIpList::allTenants()->where('organization_id', $project->organization_id)->where('project_id', $project->id)->findOrFail($entryId);
        $e->delete();
        $this->audit->log('project.firewall.ip.remove', 'project', $project->id, ['list' => $e->list, 'cidr' => $e->cidr], $project->organization_id);
        $this->traefik->push();

        return response()->noContent();
    }

    public function storeRule(Request $request, string $id)
    {
        [$project] = $this->project($request, $id, 'manage');
        $data = $request->validate([
            'name' => 'required|string|max:120', 'paths' => 'required|array|min:1', 'paths.*' => 'string|max:255',
            'limit' => 'required|integer|min:1|max:1000000', 'window_seconds' => 'sometimes|integer|min:1|max:86400',
            'action' => 'sometimes|in:block,throttle,log', 'block_minutes' => 'nullable|integer|min:1|max:525600',
        ]);
        $rule = app(Tenant::class)->as($project->organization_id, fn () => FirewallRule::create($data + ['project_id' => $project->id]));
        $this->audit->log('project.firewall.rule.add', 'project', $project->id, ['rule_id' => $rule->id, 'limit' => $data['limit']], $project->organization_id);
        $this->traefik->push();

        return $this->data(['id' => $rule->id], 201);
    }

    public function destroyRule(Request $request, string $id, string $ruleId)
    {
        [$project] = $this->project($request, $id, 'manage');
        FirewallRule::allTenants()->where('organization_id', $project->organization_id)->where('project_id', $project->id)->findOrFail($ruleId)->delete();
        $this->audit->log('project.firewall.rule.remove', 'project', $project->id, ['rule_id' => $ruleId], $project->organization_id);
        $this->traefik->push();

        return response()->noContent();
    }

    /** Lock (close to the public except allow-listed addresses) or unlock one of the project's apps. */
    public function lock(Request $request, string $id, string $appId, string $action)
    {
        [$project] = $this->project($request, $id, 'manage');
        $app = Application::allTenants()->where('project_id', $project->id)->findOrFail($appId);
        $v = $request->validate(['reason' => 'nullable|string|max:255', 'until' => 'nullable|date|after:now']);
        $reason = $v['reason'] ?? null;
        $action === 'lock'
            ? $app->forceFill(['public_access' => false, 'lock_reason' => $reason, 'locked_at' => $app->public_access === false && $app->locked_at ? $app->locked_at : now(), 'locked_by' => $request->user()->id, 'maintenance_until' => $v['until'] ?? null])->save()
            : $app->forceFill(['public_access' => true, 'lock_reason' => null, 'locked_at' => null, 'locked_by' => null, 'maintenance_until' => null] + \App\Services\MaintenanceScheduleService::endedByUnlock($app))->save();
        app(\App\Services\MaintenanceScheduleService::class)->forget($app);
        $this->audit->log("project.app.{$action}", 'project', $project->id, ['application_id' => $app->id, 'reason' => $reason], $project->organization_id);
        $this->traefik->push();

        return $this->show($request, $id);
    }
}
