<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Network;
use App\Models\Project;
use App\Models\Server;
use App\Services\AuditLogger;
use App\Services\DomainService;
use App\Services\NetworkService;
use App\Services\ProjectAccess;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * PWS-F: a project's Docker networks and how its domains reach it. Environment networks are the
 * per-project networks (NetworkService::forEnvironment, attached to the ingress Traefik); domains
 * are reported as routed through the central ingress (project DNS records) or pinned to a node's IP
 * (legacy A records), which is what decides whether moving an app to another node needs a DNS change.
 * Nothing is removed from the shared `proxy` network (see the decision in the changelog).
 */
class ProjectNetworkController extends Controller
{
    public function __construct(private ProjectAccess $access, private AuditLogger $audit, private NetworkService $networks, private DomainService $domains) {}

    private function project(Request $request, string $id, string $ability): array
    {
        $project = Project::find($id) ?? (($p = Project::allTenants()->find($id)) && $this->access->role($p, $request->user()) === 'platform_admin' ? $p : null);
        abort_unless($project, 404);

        return [$project, $this->access->authorize($project, $request->user(), $ability)];
    }

    public function show(Request $request, string $id)
    {
        [$project, $role] = $this->project($request, $id, 'view');
        $apps = Application::allTenants()->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('name')->get();
        $nets = Network::allTenants()->where('project_id', $project->id)->get();
        $servers = Server::allTenants()->whereIn('id', $apps->pluck('server_id')->merge($nets->pluck('server_id'))->filter()->unique())->get(['id', 'name', 'ip'])->keyBy('id');
        $appIds = $apps->pluck('id');
        $domains = Domain::allTenants()->where(fn ($q) => $q->where('project_id', $project->id)->orWhereIn('application_id', $appIds))->orderBy('name')->get();
        $ingressHost = $this->domains->ingressHostname();

        return $this->data([
            'can_manage' => in_array('manage', $this->access->abilities($role), true),
            'ingress' => ['hostname' => $ingressHost, 'ips' => $this->domains->ingressIps()],
            'networks' => $nets->map(fn (Network $n) => [
                'id' => $n->id, 'name' => $n->name, 'status' => $n->status, 'node' => $servers[$n->server_id]->name ?? null,
                'environment_id' => $n->environment_id, 'ingress_attached' => (bool) $n->ingress_attached_at,
            ])->values(),
            'apps' => $apps->map(function (Application $a) use ($nets, $servers) {
                $net = $a->environment_id ? $nets->first(fn ($n) => $n->environment_id === $a->environment_id && $n->server_id === $a->server_id) : null;

                return [
                    'id' => $a->id, 'name' => $a->name, 'node' => $servers[$a->server_id]->name ?? null,
                    'network' => $net?->name, 'network_status' => $net?->status,
                    'note' => match (true) {
                        ! $a->environment_id => 'Not in an environment, so it has no project network; it runs on the node\'s shared networks.',
                        ! $net => 'Its environment network on this node has not been created yet; Reconcile creates it.',
                        $net->status !== 'active' => 'Its network is still being created.',
                        ! $net->ingress_attached_at => 'The ingress has not joined its network yet.',
                        default => null,
                    },
                ];
            })->values(),
            'domains' => $domains->map(function (Domain $d) use ($apps, $servers) {
                $viaIngress = (bool) $d->dns_method && ! $d->platform_subdomain;
                $app = $apps->firstWhere('id', $d->application_id);

                return [
                    'id' => $d->id, 'name' => $d->name, 'dns_status' => $d->dns_status,
                    'routing' => $viaIngress ? 'ingress' : 'node',
                    'pinned_ip' => $viaIngress ? null : ($servers[$app?->server_id]->ip ?? null),
                    'moves_need_dns_change' => ! $viaIngress,
                ];
            })->values(),
        ]);
    }

    /** Create any missing environment networks for this project now and attach the ingress to them. */
    public function reconcile(Request $request, string $id)
    {
        [$project] = $this->project($request, $id, 'manage');
        $made = 0;
        $attached = 0;
        $skipped = [];
        app(Tenant::class)->as($project->organization_id, function () use ($project, &$made, &$attached, &$skipped) {
            $pairs = Application::allTenants()->where('project_id', $project->id)->whereNotNull('environment_id')->whereNotNull('server_id')->whereNull('deleted_at')->get(['environment_id', 'server_id'])
                ->unique(fn ($a) => $a->environment_id.'|'.$a->server_id);
            foreach ($pairs as $a) {
                $env = Environment::allTenants()->find($a->environment_id);
                $server = Server::allTenants()->find($a->server_id); // may belong to another organisation
                if (! $env || ! $server) {
                    $skipped[] = $a->environment_id.'|'.$a->server_id;

                    continue;
                }
                $this->networks->forEnvironment($env, $server)->wasRecentlyCreated && $made++;
            }
            foreach (Network::allTenants()->where('project_id', $project->id)->where('status', 'active')->get() as $n) {
                $attached += $this->networks->attachIngress($n);
            }
        });
        $this->audit->log('project.networks.reconcile', 'project', $project->id, ['created' => $made, 'attached' => $attached, 'skipped' => $skipped], $project->organization_id);

        return $this->data(['created' => $made, 'ingress_attachments' => $attached, 'skipped' => $skipped]);
    }
}
