<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Domain;
use App\Models\FirewallEvent;
use App\Models\FirewallIpList;
use App\Models\FirewallIsp;
use App\Models\FirewallRule;
use App\Models\Project;
use App\Services\TraefikConfigService;
use Illuminate\Http\Request;

/**
 * Project → Firewall (admin and customer): what protects this project's apps. IP entries, rate-limit
 * and ISP rules scoped to the project, one of its apps or one of its domains (organisation-wide rules
 * are counted, not listed), recent blocked requests and each app's lock state. IP entries can be added
 * and removed here, but only within the project.
 */
class ProjectFirewallController extends Controller
{
    public function __construct(private TraefikConfigService $traefik) {}

    private function scope(Request $request, string $id): array
    {
        $project = Project::findOrFail($id);
        $apps = Application::where('project_id', $project->id)->when($request->is('api/v1/app/*'), fn ($q) => $q->where('kind', 'customer'))->orderBy('name')->get();
        $domains = $apps->isEmpty() ? collect() : Domain::whereIn('application_id', $apps->pluck('id'))->get(['id', 'name', 'application_id', 'ip_access']);

        return [$project, $apps, $domains];
    }

    private function inProject($q, $project, $apps, $domains)
    {
        return $q->where(fn ($q) => $q->where('project_id', $project->id)
            ->orWhereIn('application_id', $apps->pluck('id')->all() ?: ['-'])
            ->orWhereIn('domain_id', $domains->pluck('id')->all() ?: ['-']));
    }

    private function label($m, $project, $apps, $domains): string
    {
        return match (true) {
            (bool) $m->project_id => 'whole project',
            (bool) $m->application_id => 'app '.($apps->firstWhere('id', $m->application_id)?->name ?? ''),
            (bool) $m->domain_id => $domains->firstWhere('id', $m->domain_id)?->name ?? 'domain',
            default => 'all domains',
        };
    }

    public function show(Request $request, string $id)
    {
        [$project, $apps, $domains] = $this->scope($request, $id);
        $ips = $this->inProject(FirewallIpList::query()->active(), $project, $apps, $domains)->latest()->get();
        $rules = $this->inProject(FirewallRule::query(), $project, $apps, $domains)->orderBy('priority')->get();
        $isps = $this->inProject(FirewallIsp::query(), $project, $apps, $domains)->orderBy('asn')->get();
        $events = $domains->isEmpty() ? collect() : FirewallEvent::whereIn('domain_id', $domains->pluck('id'))->where('occurred_at', '>=', now()->subDay())->latest('occurred_at')->limit(50)->get();

        return $this->data([
            'project' => ['id' => $project->id, 'name' => $project->name],
            'apps' => $apps->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'public_access' => $a->public_access !== false, 'lock_reason' => $a->lock_reason])->values(),
            'domains' => $domains->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'ip_access' => $d->ip_access ?? 'open'])->values(),
            'ips' => $ips->map(fn ($e) => ['id' => $e->id, 'list' => $e->list, 'cidr' => $e->cidr, 'note' => $e->note, 'expires_at' => $e->expires_at, 'scope' => $this->label($e, $project, $apps, $domains)])->values(),
            'rules' => $rules->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'action' => $r->action, 'rate' => $r->limit.' / '.$r->window_seconds.'s', 'enabled' => (bool) $r->enabled, 'scope' => $this->label($r, $project, $apps, $domains)])->values(),
            'isps' => $isps->map(fn ($i) => ['asn' => $i->asn, 'name' => $i->name, 'state' => $i->state, 'scope' => $this->label($i, $project, $apps, $domains)])->values(),
            'org_wide' => ['ips' => FirewallIpList::active()->whereNull('domain_id')->whereNull('application_id')->whereNull('project_id')->count(),
                'rules' => FirewallRule::where('enabled', true)->whereNull('domain_id')->whereNull('application_id')->whereNull('project_id')->count()],
            'events' => $events->map(fn ($e) => $e->only(['occurred_at', 'action', 'ip', 'country', 'method', 'path', 'rule_name']) + ['domain' => $domains->firstWhere('id', $e->domain_id)?->name])->values(),
            'blocked_24h' => $events->whereIn('action', ['block', 'throttle'])->count(),
        ]);
    }

    public function storeIp(Request $request, string $id)
    {
        [$project, $apps] = $this->scope($request, $id);
        $data = $request->validate([
            'list' => 'required|in:allow,block', 'cidr' => 'required|string|max:64', 'note' => 'nullable|string|max:255',
            'application_id' => 'nullable|uuid', 'expires_hours' => 'nullable|integer|min:1|max:8760',
        ]);
        if (! empty($data['application_id']) && ! $apps->contains('id', $data['application_id'])) {
            throw ApiException::validation(['application_id' => ['That app is not in this project.']]);
        }
        $cidr = FirewallIpList::normalise($data['cidr']);
        if (! $cidr) {
            throw ApiException::validation(['cidr' => ['Enter an IP address or a CIDR range, such as 203.0.113.0/24.']]);
        }
        if ($data['list'] === 'block' && ($ip = $request->ip()) && FirewallIpList::contains($cidr, $ip) && ! $request->boolean('confirm')) {
            throw ApiException::conflict("{$cidr} includes your own address ({$ip}). Send confirm to block it anyway.");
        }
        $scope = ! empty($data['application_id']) ? ['application_id' => $data['application_id']] : ['project_id' => $project->id];
        if (FirewallIpList::where('list', $data['list'])->where('cidr', $cidr)->where($scope)->exists()) {
            throw ApiException::conflict("{$cidr} is already on this {$data['list']} list.");
        }
        $entry = FirewallIpList::create($scope + ['list' => $data['list'], 'cidr' => $cidr, 'note' => $data['note'] ?? null,
            'expires_at' => ! empty($data['expires_hours']) ? now()->addHours((int) $data['expires_hours']) : null]);
        app(\App\Services\AuditLogger::class)->log('firewall.ip.add', 'projects', $project->id, ['cidr' => $cidr, 'list' => $data['list'], 'scope' => $scope]);
        $this->traefik->push();

        return $this->data(['id' => $entry->id, 'cidr' => $cidr], 201);
    }

    public function destroyIp(Request $request, string $id, string $entryId)
    {
        [$project, $apps, $domains] = $this->scope($request, $id);
        $entry = $this->inProject(FirewallIpList::query(), $project, $apps, $domains)->findOrFail($entryId);
        app(\App\Services\AuditLogger::class)->log('firewall.ip.remove', 'projects', $project->id, ['cidr' => $entry->cidr, 'list' => $entry->list, 'note' => $entry->note]);
        $entry->delete();
        $this->traefik->push();

        return response()->noContent();
    }
}
