<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\FirewallBlockedIp;
use App\Models\FirewallEvent;
use App\Models\FirewallIpList;
use App\Models\Job;
use Illuminate\Support\Carbon;

/**
 * Firewall → Blocked IPs. Two kinds of block are listed together:
 * - manual: block-list entries (FirewallIpList), enforced by Traefik and, when on, CrowdSec;
 * - automatic: CrowdSec bans (rate limits, scenarios, by hand with cscli), read from every ingress.
 * Actions: unblock, extend, make permanent, move to the allow list. Every action is audited with
 * who, why, when and the previous state. Unblocking an address never touches an app lock or a rule.
 */
class BlockedIpService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs, private TraefikConfigService $traefik) {}

    /** Asks every ingress for its current bans. */
    public function refresh(): int
    {
        $n = 0;
        foreach ($this->traefik->ingressServers() as $server) {
            $job = $this->jobs->create('crowdsec.decisions', ['execute'], null, ['server_id' => $server->id, 'op' => 'list'], null, $server->organization_id);
            $this->bus->dispatch($server, 'crowdsec.decisions', ['op' => 'list'], $job);
            $n++;
        }

        return $n;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, (string) ($command->message ?: 'CrowdSec did not answer.'), 'execute');

            return;
        }
        if (($command->payload['op'] ?? 'list') === 'list') {
            FirewallBlockedIp::where('server_id', $command->server_id)->delete();
            foreach ((array) ($command->output['decisions'] ?? []) as $d) {
                if (($d['type'] ?? 'ban') !== 'ban' || empty($d['ip'])) {
                    continue;
                }
                FirewallBlockedIp::create([
                    'server_id' => $command->server_id, 'decision_id' => $d['id'] ?? null, 'ip' => (string) $d['ip'],
                    'origin' => (string) ($d['origin'] ?? 'crowdsec'), 'scenario' => $d['scenario'] ?? null,
                    'country' => $d['country'] ?: null, 'events' => (int) ($d['events'] ?? 0),
                    'started_at' => ! empty($d['at']) ? Carbon::parse($d['at']) : null,
                    'expires_at' => ! empty($d['until']) ? Carbon::parse($d['until']) : null,
                ]);
            }
        }
        $this->jobs->succeed($job, ['op' => $command->payload['op'] ?? 'list']);
    }

    /** Both kinds, newest first, with blocked-request counts and last activity from the event log. */
    public function list(): array
    {
        $manual = FirewallIpList::active()->where('list', 'block')->with('domain:id,name')->get()->map(fn ($e) => [
            'key' => 'manual:'.$e->id, 'ip' => $e->cidr, 'kind' => 'manual', 'reason' => $e->note ?: 'Manual block',
            'scope' => $e->domain?->name ?? 'all domains', 'country' => null, 'started_at' => $e->created_at, 'expires_at' => $e->expires_at, 'list_id' => $e->id,
        ]);
        $auto = FirewallBlockedIp::query()->orderByDesc('started_at')->get()->groupBy('ip')->map(function ($rows) {
            $r = $rows->first();

            return ['key' => 'auto:'.$r->ip, 'ip' => $r->ip, 'kind' => $r->origin === 'cscli' ? 'by hand' : 'automatic',
                'reason' => $this->reasonFor($r->scenario), 'scenario' => $r->scenario, 'scope' => 'all domains', 'country' => $r->country,
                'started_at' => $r->started_at, 'expires_at' => $rows->max('expires_at'), 'nodes' => $rows->count()];
        })->values();
        $rows = $manual->concat($auto)->values();
        $ips = $rows->pluck('ip')->map(fn ($c) => explode('/', $c)[0])->unique()->values();
        $stats = FirewallEvent::whereIn('ip', $ips)->selectRaw('ip, count(*) as n, max(occurred_at) as last')->groupBy('ip')->get()->keyBy('ip');

        return $rows->map(function ($r) use ($stats) {
            $s = $stats[explode('/', $r['ip'])[0]] ?? null;

            return $r + ['blocked_requests' => (int) ($s->n ?? 0), 'last_activity' => $s->last ?? null];
        })->sortByDesc('started_at')->values()->all();
    }

    private function reasonFor(?string $scenario): string
    {
        return match (true) {
            ! $scenario => 'Security rule',
            str_contains($scenario, 'dmp/fw-') || str_contains($scenario, 'rate') || str_contains($scenario, 'crawl') || str_contains($scenario, 'bf') => 'Rate limit',
            str_contains($scenario, 'probing') || str_contains($scenario, 'scan') || str_contains($scenario, 'cve') || str_contains($scenario, 'traversal') => 'Suspicious activity',
            str_starts_with($scenario, 'dmp/block-') => 'Manual block',
            default => 'Security rule',
        };
    }

    /** Lifts a block. reason is free text kept in the audit log. */
    public function unblock(string $key, ?string $reason): void
    {
        [$kind, $id] = explode(':', $key, 2) + [1 => ''];
        if ($kind === 'manual') {
            $entry = FirewallIpList::findOrFail($id);
            $this->audit('firewall.unblock', $entry->cidr, $reason, ['kind' => 'manual', 'note' => $entry->note, 'expires_at' => $entry->expires_at?->toIso8601String(), 'scope' => $entry->domain_id]);
            $entry->delete();
            $this->traefik->push();

            return;
        }
        $this->guardIp($id);
        $previous = FirewallBlockedIp::where('ip', $id)->get(['scenario', 'origin', 'expires_at'])->toArray();
        $this->everywhere('delete', ['ip' => $id]);
        FirewallBlockedIp::where('ip', $id)->delete();
        $this->audit('firewall.unblock', $id, $reason, ['kind' => 'automatic', 'decisions' => $previous]);
    }

    /** Extends a block by a duration such as "4h" or "7d". */
    public function extend(string $key, string $duration, ?string $reason): void
    {
        if (! preg_match('/^([0-9]{1,4})([hd])$/', $duration, $m)) {
            throw ApiException::validation(['duration' => ['Use hours or days, e.g. 4h or 7d.']]);
        }
        [$kind, $id] = explode(':', $key, 2) + [1 => ''];
        if ($kind === 'manual') {
            $entry = FirewallIpList::findOrFail($id);
            $from = $entry->expires_at;
            $entry->update(['expires_at' => ($from && $from->isFuture() ? $from : now())->copy()->add((int) $m[1], $m[2] === 'h' ? 'hours' : 'days')]);
            $this->audit('firewall.extend', $entry->cidr, $reason, ['kind' => 'manual', 'expires_at' => $from?->toIso8601String(), 'by' => $duration]);
            $this->traefik->push();

            return;
        }
        $this->guardIp($id);
        $this->everywhere('add', ['ip' => $id, 'duration' => $duration, 'reason' => 'extended from the panel']);
        $this->audit('firewall.extend', $id, $reason, ['kind' => 'automatic', 'by' => $duration]);
    }

    /** Turns any block into a permanent block-list entry (all domains). */
    public function permanent(string $key, ?string $reason): void
    {
        [$kind, $id] = explode(':', $key, 2) + [1 => ''];
        if ($kind === 'manual') {
            $entry = FirewallIpList::findOrFail($id);
            $this->audit('firewall.permanent', $entry->cidr, $reason, ['kind' => 'manual', 'expires_at' => $entry->expires_at?->toIso8601String()]);
            $entry->update(['expires_at' => null]);
        } else {
            $cidr = $this->guardIp($id);
            FirewallIpList::firstOrCreate(['list' => 'block', 'cidr' => $cidr, 'domain_id' => null], ['note' => 'Made permanent from an automatic block'.($reason ? ": {$reason}" : '')]);
            $this->audit('firewall.permanent', $id, $reason, ['kind' => 'automatic']);
        }
        $this->traefik->push();
    }

    /** Lifts the block and puts the address on the allow list. */
    public function allow(string $key, ?string $reason): void
    {
        [$kind, $id] = explode(':', $key, 2) + [1 => ''];
        $cidr = $kind === 'manual' ? FirewallIpList::findOrFail($id)->cidr : $this->guardIp($id);
        $this->unblock($key, $reason);
        FirewallIpList::firstOrCreate(['list' => 'allow', 'cidr' => $cidr, 'domain_id' => null], ['note' => 'Allowed from Blocked IPs'.($reason ? ": {$reason}" : '')]);
        $this->audit('firewall.allow', $cidr, $reason, ['from' => $kind]);
        $this->traefik->push();
    }

    private function guardIp(string $ip): string
    {
        $cidr = FirewallIpList::normalise($ip);
        if (! $cidr) {
            throw ApiException::validation(['ip' => ['Not an IP address.']]);
        }

        return $cidr;
    }

    private function everywhere(string $op, array $payload): void
    {
        foreach ($this->traefik->ingressServers() as $server) {
            $job = $this->jobs->create('crowdsec.decisions', ['execute'], null, ['server_id' => $server->id, 'op' => $op], null, $server->organization_id);
            $this->bus->dispatch($server, 'crowdsec.decisions', ['op' => $op] + $payload, $job);
        }
    }

    private function audit(string $action, string $ip, ?string $reason, array $previous): void
    {
        app(AuditLogger::class)->log($action, 'firewall_ip', $ip, ['ip' => $ip, 'reason' => $reason, 'previous' => $previous]);
    }
}
