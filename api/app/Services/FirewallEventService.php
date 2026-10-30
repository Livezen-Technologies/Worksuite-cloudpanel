<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Models\AgentCommand;
use App\Models\Domain;
use App\Models\FirewallEvent;
use App\Models\FirewallProtectedPath;
use App\Models\FirewallRule;
use App\Models\Job;
use Illuminate\Support\Facades\Cache;

/**
 * FW-001: fills the firewall event log from CrowdSec. Every few minutes each
 * ingress reports its recent alerts (crowdsec.alerts); each alert becomes a
 * firewall event in the organisation it belongs to, found from the scenario
 * name the panel gave it, or for CrowdSec's own scenarios from the host.
 */
class FirewallEventService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs, private TraefikConfigService $traefik) {}

    /** Queues crowdsec.alerts on every ingress. Returns how many were queued. */
    public function sync(): int
    {
        if (! CrowdSecConfigService::enabled()) {
            return 0;
        }
        $count = 0;
        foreach ($this->traefik->ingressServers() as $server) {
            $job = $this->jobs->create('crowdsec.alerts', ['execute'], null, ['server_id' => $server->id], null, $server->organization_id);
            $this->bus->dispatch($server, 'crowdsec.alerts', ['since' => '10m'], $job);
            $count++;
        }

        return $count;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, (string) ($command->message ?: 'Could not read CrowdSec alerts.'), 'execute');

            return;
        }
        $recorded = 0;
        foreach ((array) ($command->output['alerts'] ?? []) as $alert) {
            // Each poll overlaps the last; record an alert once per ingress.
            $seen = 'crowdsec-alert:'.$command->server_id.':'.($alert['id'] ?? '');
            if (empty($alert['id']) || ! Cache::add($seen, 1, now()->addDay())) {
                continue;
            }
            if ($this->record($alert)) {
                $recorded++;
            }
        }
        $this->jobs->succeed($job, ['recorded' => $recorded]);
    }

    /** One CrowdSec alert as a firewall event; false when it belongs to no organisation. */
    public function record(array $alert): bool
    {
        $scenario = (string) ($alert['scenario'] ?? '');
        $action = match ($alert['decision'] ?? null) {
            'ban' => 'block', 'captcha' => 'challenge', default => 'log',
        };
        $orgId = null;
        $rule = null;
        $name = $scenario;
        $domainId = null;

        if (preg_match('#^dmp/fw-([0-9a-f-]{36})$#', $scenario, $m) && ($rule = FirewallRule::allTenants()->find($m[1]))) {
            $orgId = $rule->organization_id;
            $name = $rule->name;
            $domainId = $rule->domain_id;
            $rule->increment('hits');
        } elseif (preg_match('#^dmp/geo-([0-9a-f-]{36})-(block|challenge)#', $scenario, $m)) {
            [$orgId, $name] = [$m[1], 'Country policy'];
        } elseif (preg_match('#^dmp/path-([0-9a-f-]{36})$#', $scenario, $m) && ($path = FirewallProtectedPath::allTenants()->find($m[1]))) {
            [$orgId, $name, $domainId] = [$path->organization_id, 'Protected path '.$path->path, $path->domain_id];
        }

        $host = strtolower((string) ($alert['host'] ?? ''));
        if ($host !== '') {
            $domain = Domain::allTenants()->whereIn('name', [$host, preg_replace('/^www\./', '', $host)])
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))->first();
            $domainId ??= $domain?->id;
            $orgId ??= $domain?->organization_id;
        }
        if (! $orgId) {
            return false;
        }
        FirewallEvent::allTenants()->create([
            'organization_id' => $orgId,
            'domain_id' => $domainId,
            'firewall_rule_id' => $rule?->id,
            'occurred_at' => isset($alert['at']) ? \Illuminate\Support\Carbon::parse($alert['at']) : now(),
            'action' => $action,
            'ip' => substr((string) ($alert['ip'] ?? ''), 0, 45),
            'country' => isset($alert['country']) && strlen($alert['country']) === 2 ? strtoupper($alert['country']) : null,
            'method' => isset($alert['method']) ? substr($alert['method'], 0, 10) : null,
            'path' => isset($alert['path']) ? substr($alert['path'], 0, 500) : null,
            'rule_name' => substr($name, 0, 255),
        ]);

        return true;
    }
}
