<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DnsCheck;
use App\Models\Domain;
use App\Models\Project;
use App\Models\ProjectDomainGrant;
use App\Models\User;

/**
 * PDNS: a project's DNS & Domains. Builds on DomainService (records, verification, certificates,
 * zone publishing) and adds what is per project: the primary domain, grantable permissions, the
 * change history and removal rules. Everything here runs tenant-scoped.
 */
class ProjectDomainService
{
    /** Grantable permissions. The organisation owner holds all of them on every project. */
    public const PERMISSIONS = [
        'view' => 'View domains and DNS',
        'add' => 'Add and verify domains',
        'records' => 'Update DNS records',
        'primary' => 'Change the primary domain',
        'ssl' => 'Manage SSL certificates',
        'remove' => 'Remove domains',
    ];

    public function __construct(private DomainService $domains, private AuditLogger $audit) {}

    public function permissionsFor(User $user, Project $project): array
    {
        $role = $user->membership($project->organization_id)?->role;
        if ($role === 'owner') {
            return array_keys(self::PERMISSIONS);
        }
        $granted = (array) (ProjectDomainGrant::where('project_id', $project->id)->where('user_id', $user->id)->value('permissions') ?? []);
        // Every member who can open the project can see its domains.
        $granted[] = 'view';

        return array_values(array_unique(array_intersect(array_keys(self::PERMISSIONS), $granted)));
    }

    public function authorize(User $user, Project $project, string $permission): void
    {
        if (! in_array($permission, $this->permissionsFor($user, $project), true)) {
            throw ApiException::forbidden('You need the "'.self::PERMISSIONS[$permission].'" permission on this project. Ask the project owner.');
        }
    }

    public function isOwner(User $user, Project $project): bool
    {
        return $user->membership($project->organization_id)?->role === 'owner';
    }

    /** The project's apps (customer kind only). */
    public function apps(Project $project)
    {
        return Application::where('kind', 'customer')->where('project_id', $project->id)->orderBy('name')->get();
    }

    public function query(Project $project)
    {
        $appIds = $this->apps($project)->pluck('id');

        return Domain::with('certificate')->where(fn ($q) => $q->whereIn('application_id', $appIds)
            ->orWhere(fn ($q) => $q->where('project_id', $project->id)->whereNull('application_id')));
    }

    public function find(Project $project, string $id): Domain
    {
        return $this->query($project)->findOrFail($id);
    }

    public function overview(Project $project): array
    {
        $rows = $this->query($project)->orderByDesc('is_primary')->orderBy('name')->get();
        $list = $rows->map(fn ($d) => $this->present($d))->values();

        return [
            'counts' => [
                'total' => $rows->count(),
                'verified' => $rows->where('dns_status', 'verified')->count(),
                'pending' => $rows->where('dns_status', '!=', 'verified')->count(),
                'ssl_active' => $rows->where('https_status', 'active')->count(),
            ],
            'domains' => $list,
            'apps' => $this->apps($project)->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status])->values(),
            'ingress' => ['hostname' => $this->domains->ingressHostname(), 'ips' => $this->domains->ingressIps()],
            'platform_domain' => config('platform.domain'),
        ];
    }

    public function present(Domain $domain): array
    {
        $last = DnsCheck::where('domain_id', $domain->id)->where('kind', 'validation')->latest('created_at')->first();

        return $this->domains->toApi($domain) + [
            'last_validation' => $last ? ['ok' => $last->ok, 'at' => $last->created_at?->toIso8601ZuluString(), 'checks' => $last->checks] : null,
            'status' => $this->status($domain),
        ];
    }

    /** One word for the list: active | verifying | ownership | failed. */
    public function status(Domain $domain): string
    {
        if ($domain->ownership_token && ! $domain->ownership_verified_at) {
            return 'awaiting ownership';
        }
        if ($domain->dns_status !== 'verified') {
            return 'awaiting DNS';
        }

        return match ($domain->https_status) { 'active' => 'active', 'failed' => 'SSL failed', default => 'issuing SSL' };
    }

    /**
     * Adds a custom domain (kind=custom) or a Livezen Cloud subdomain (kind=platform, $name is the
     * label). The target app must be in the project.
     */
    public function add(Project $project, array $data): Domain
    {
        $app = $this->apps($project)->firstWhere('id', $data['app_id']) ?? throw ApiException::validation(['app_id' => ['Choose an app in this project.']]);
        $platform = $data['kind'] === 'platform';
        $name = strtolower(trim($data['name'], '. '));
        if ($platform) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
                throw ApiException::validation(['name' => ['Use letters, digits and hyphens only, like my-shop.']]);
            }
            $name .= '.'.config('platform.domain');
        }
        $first = ! $this->query($project)->exists();
        $domain = $this->domains->create([
            'name' => $name, 'application_id' => $app->id, 'project_id' => $project->id, 'target_port' => $app->port,
            'www_redirect' => ! $platform && (bool) ($data['www_redirect'] ?? false),
            'platform_subdomain' => $platform,
            'dns_method' => $platform ? 'platform' : ($data['method'] ?? 'manual'),
            'is_primary' => $first,
        ], $platform);
        $this->audit->log('domain.added', 'domains', $domain->id, ['domain' => $domain->name, 'project_id' => $project->id, 'app' => $app->name, 'method' => $domain->dns_method]);

        $published = [];
        if (! $platform && ($data['method'] ?? 'manual') === 'provider') {
            $published = $domain->publishedRecords ?? $this->domains->publishToZone($domain);
            if ($published) {
                $this->audit->log('domain.dns_published', 'domains', $domain->id, ['domain' => $domain->name, 'project_id' => $project->id, 'records' => $published]);
            }
        }
        $domain->publishedRecords = $published;

        return $domain;
    }

    /** Writes the domain's records into the connected provider zone again; never overwrites. */
    public function publish(Project $project, Domain $domain): array
    {
        if (! \App\Models\DnsZone::forDomain($domain->name, $domain->organization_id)) {
            throw ApiException::conflict('No connected DNS provider holds this domain. Connect its zone first, or add the records by hand.');
        }
        $out = $this->domains->publishToZone($domain);
        $this->audit->log('domain.dns_published', 'domains', $domain->id, ['domain' => $domain->name, 'project_id' => $project->id, 'records' => $out]);

        return $out;
    }

    public function makePrimary(Project $project, Domain $domain): void
    {
        if ($domain->dns_status !== 'verified') {
            throw ApiException::conflict('Only a verified domain can be primary.');
        }
        $previous = $this->query($project)->where('is_primary', true)->whereKeyNot($domain->id)->value('name');
        $this->query($project)->where('is_primary', true)->update(['is_primary' => false]);
        $domain->update(['is_primary' => true]);
        $this->audit->log('domain.primary_changed', 'domains', $domain->id, ['domain' => $domain->name, 'previous' => $previous, 'project_id' => $project->id]);
    }

    public function changeApp(Project $project, Domain $domain, string $appId): void
    {
        $app = $this->apps($project)->firstWhere('id', $appId) ?? throw ApiException::validation(['app_id' => ['Choose an app in this project.']]);
        $from = $domain->application_id;
        $domain->update(['application_id' => $app->id, 'target_port' => $app->port]);
        \App\Models\Route::where('domain_id', $domain->id)->update(['application_id' => $app->id, 'port' => $app->port]);
        $this->audit->log('domain.routing_changed', 'domains', $domain->id, ['domain' => $domain->name, 'from_app_id' => $from, 'to_app' => $app->name, 'project_id' => $project->id]);
    }

    /** An active primary domain needs the typed name back as confirmation. */
    public function remove(Project $project, Domain $domain, ?string $confirm): void
    {
        if ($domain->is_primary && $domain->dns_status === 'verified' && strtolower(trim((string) $confirm)) !== $domain->name) {
            throw ApiException::validation(['confirm' => ["{$domain->name} is the project's active primary domain. Type its name to remove it."]]);
        }
        $meta = ['domain' => $domain->name, 'project_id' => $project->id, 'was_primary' => (bool) $domain->is_primary];
        $id = $domain->id;
        $domain->delete();
        $this->audit->log('domain.removed', 'domains', $id, $meta);
    }

    public function renewCertificate(Project $project, Domain $domain): array
    {
        $cert = $this->domains->issueCertificate($domain);
        $this->audit->log('domain.certificate_requested', 'domains', $domain->id, ['domain' => $domain->name, 'project_id' => $project->id, 'status' => $cert->status]);

        return ['status' => $cert->status, 'last_error' => $cert->last_error];
    }

    public function history(Project $project, int $limit = 100): array
    {
        return AuditLog::where('resource_type', 'domains')->where('meta->project_id', $project->id)->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($l) => ['id' => $l->id, 'action' => $l->action, 'actor' => $l->actor, 'domain' => $l->meta['domain'] ?? null, 'meta' => array_diff_key((array) $l->meta, ['project_id' => 1]),
                'at' => $l->created_at?->toIso8601ZuluString()])->values()->all();
    }

    public function grants(Project $project): array
    {
        $members = \App\Models\Membership::with('user')->where('organization_id', $project->organization_id)->where('status', 'active')->get();
        $grants = ProjectDomainGrant::where('project_id', $project->id)->get()->keyBy('user_id');

        return $members->map(fn ($m) => [
            'user_id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email, 'role' => $m->role,
            'owner' => $m->role === 'owner',
            'permissions' => $m->role === 'owner' ? array_keys(self::PERMISSIONS) : array_values(array_unique(array_merge(['view'], (array) ($grants[$m->user_id]->permissions ?? [])))),
        ])->values()->all();
    }

    public function grant(Project $project, string $userId, array $permissions): void
    {
        $member = \App\Models\Membership::where('organization_id', $project->organization_id)->where('user_id', $userId)->first()
            ?? throw ApiException::validation(['user_id' => ['That person is not a member of this organisation.']]);
        if ($member->role === 'owner') {
            throw ApiException::conflict('The organisation owner always has every permission.');
        }
        $permissions = array_values(array_intersect(array_keys(self::PERMISSIONS), $permissions));
        ProjectDomainGrant::updateOrCreate(['project_id' => $project->id, 'user_id' => $userId], ['permissions' => $permissions, 'granted_by' => request()->user()?->id]);
        $this->audit->log('domain.permissions_changed', 'domains', null, ['project_id' => $project->id, 'user_id' => $userId, 'permissions' => $permissions]);
    }
}
