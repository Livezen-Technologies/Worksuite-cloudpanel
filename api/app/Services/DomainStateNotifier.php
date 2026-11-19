<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectDomainGrant;
use App\Models\User;

/**
 * PDNS-2: tells a project's people when the scheduled re-check changes a domain's state —
 * ownership proven, DNS verified (seen by the resolvers) or broken again, certificate issued or
 * HTTPS broken again. The state a notification last went out for is kept on the domain
 * (domains.notified_state), so an unchanged state never notifies twice, and the first check of a
 * domain only records where it stands.
 */
class DomainStateNotifier
{
    public function __construct(private EmailTemplateService $mail, private AuditLogger $audit) {}

    public function state(Domain $domain): string
    {
        $owned = ! $domain->ownership_token || $domain->ownership_verified_at ? 1 : 0;

        return "o{$owned}|d:".($domain->dns_status === 'verified' ? 'verified' : 'pending').'|h:'.($domain->https_status === 'active' ? 'active' : ($domain->https_status === 'failed' ? 'failed' : 'pending'));
    }

    /** Before a check: a domain never notified about starts from its current state. */
    public function baseline(Domain $domain): void
    {
        if ($domain->notified_state === null) {
            $domain->forceFill(['notified_state' => $this->state($domain)])->saveQuietly();
        }
    }

    /** After a check: notifies each change since the last notified state. Returns the changes sent. */
    public function afterCheck(Domain $domain): array
    {
        $domain = $domain->fresh() ?? $domain;
        $now = $this->state($domain);
        $before = $domain->notified_state;
        if ($before === null || $before === $now || ! $domain->project_id) {
            if ($before === null) {
                $domain->forceFill(['notified_state' => $now])->saveQuietly();
            }

            return [];
        }
        $changes = $this->changes($this->parse($before), $this->parse($now), $domain);
        $domain->forceFill(['notified_state' => $now])->saveQuietly();
        if (! $changes) {
            return [];
        }
        $project = Project::allTenants()->find($domain->project_id);
        $recipients = $project ? $this->recipients($project) : collect();
        foreach ($changes as $c) {
            foreach ($recipients as $user) {
                $this->mail->notify('domain_state_changed', $user, ['domain' => $domain->name, 'project' => (string) $project->name, 'change' => $c['change'], 'detail' => $c['detail']]);
            }
            $this->audit->log('domain.state_changed', 'domains', $domain->id, ['domain' => $domain->name, 'project_id' => $domain->project_id, 'event' => $c['event'],
                'change' => $c['change'], 'notified' => $recipients->count()], $domain->organization_id, actor: 'system');
        }

        return $changes;
    }

    /** The organisation's owners, and every member the project owner granted a DNS permission. */
    public function recipients(Project $project)
    {
        $members = Membership::allTenants()->where('organization_id', $project->organization_id)->where('status', 'active')->get(['user_id', 'role']);
        $granted = ProjectDomainGrant::withoutGlobalScopes()->where('project_id', $project->id)->pluck('user_id')->all();
        $ids = $members->filter(fn ($m) => $m->role === 'owner' || in_array($m->user_id, $granted, true))->pluck('user_id')->unique()->all();

        return User::whereIn('id', $ids)->get();
    }

    private function parse(string $state): array
    {
        preg_match('/^o(\d)\|d:(\w+)\|h:(\w+)$/', $state, $m);

        return ['owned' => ($m[1] ?? '0') === '1', 'dns' => $m[2] ?? 'pending', 'https' => $m[3] ?? 'pending'];
    }

    private function changes(array $a, array $b, Domain $domain): array
    {
        $out = [];
        if (! $a['owned'] && $b['owned']) {
            $out[] = ['event' => 'ownership_verified', 'change' => 'Ownership verified', 'detail' => "The TXT record proving you own {$domain->name} was found."];
        }
        if ($a['dns'] !== 'verified' && $b['dns'] === 'verified') {
            $out[] = ['event' => 'dns_verified', 'change' => 'DNS verified', 'detail' => "{$domain->name} now points at the platform ingress and the records have propagated."];
        } elseif ($a['dns'] === 'verified' && $b['dns'] !== 'verified') {
            $out[] = ['event' => 'dns_broken', 'change' => 'DNS broke again', 'detail' => "{$domain->name} no longer resolves to the platform ingress. Check its records, then run Validate DNS."];
        }
        if ($a['https'] !== 'active' && $b['https'] === 'active') {
            $out[] = ['event' => 'certificate_issued', 'change' => 'Certificate issued', 'detail' => "HTTPS is on for {$domain->name}."];
        } elseif ($a['https'] === 'active' && $b['https'] !== 'active') {
            $out[] = ['event' => 'https_broken', 'change' => 'HTTPS broke again', 'detail' => "The certificate for {$domain->name} is no longer serving.".($b['https'] === 'failed' ? ' The last issuance failed.' : '')];
        }

        return $out;
    }
}
