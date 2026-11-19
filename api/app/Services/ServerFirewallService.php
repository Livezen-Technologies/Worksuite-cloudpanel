<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\FirewallIpList;
use App\Models\Job;
use App\Models\Server;
use App\Models\ServerFirewall;

/**
 * Host firewall for one server, applied by the agent as its own nftables table (UFW, Docker and
 * fail2ban rules are left alone). Safety: private networks, loopback, the panel host and the
 * always-allowed admin ranges are never blocked; "allow only" must name ports so it cannot cut off
 * SSH or container traffic; every apply arms an automatic rollback that the panel confirms once
 * the agent reports success.
 */
class ServerFirewallService implements HandlesCommandResults
{
    public const PRIVATE = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '100.64.0.0/10', 'fc00::/7', '::1/128'];

    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    public function for(Server $server): ServerFirewall
    {
        return ServerFirewall::firstOrCreate(['server_id' => $server->id], ['organization_id' => $server->organization_id, 'mode' => 'block', 'block' => [], 'allow' => [], 'ports' => []]);
    }

    /** Addresses that are always accepted, whatever the rules say. */
    public function neverBlock(): array
    {
        $panel = array_filter([config('platform.firewall.panel_ip'), Server::allTenants()->whereIn('name', ['livezen-panel-01'])->value('ip')]);

        return array_values(array_unique(array_merge(self::PRIVATE, $panel, (array) config('platform.firewall.always_allow', []))));
    }

    /** Validates and stores the settings. */
    public function save(ServerFirewall $fw, array $data): ServerFirewall
    {
        $norm = function (array $list, string $field) {
            return array_values(array_unique(array_map(function ($v) use ($field) {
                $c = FirewallIpList::normalise((string) $v);
                if (! $c) {
                    throw ApiException::validation([$field => ["“{$v}” is not an IP address or CIDR range."]]);
                }

                return $c;
            }, array_filter(array_map('trim', $list)))));
        };
        $mode = $data['mode'] ?? $fw->mode;
        $allow = isset($data['allow']) ? $norm((array) $data['allow'], 'allow') : (array) $fw->allow;
        $block = isset($data['block']) ? $norm((array) $data['block'], 'block') : (array) $fw->block;
        $ports = array_key_exists('ports', $data) ? array_values(array_unique(array_map('intval', (array) $data['ports']))) : (array) $fw->ports;
        if ($mode === 'allow_only' && (! $allow || ! $ports)) {
            throw ApiException::validation(['mode' => ['"Allow only" needs at least one allowed address and the ports it covers (e.g. 3306), so it cannot cut off SSH or the sites.']]);
        }
        foreach ($block as $c) {
            foreach ($this->neverBlock() as $safe) {
                if (FirewallIpList::contains($c, explode('/', $safe)[0]) || FirewallIpList::contains($safe, explode('/', $c)[0])) {
                    throw ApiException::validation(['block' => ["{$c} overlaps {$safe}, which is always allowed (private network, the panel or an admin range)."]]);
                }
            }
        }
        $fw->fill(['mode' => $mode, 'allow' => $allow, 'block' => $block, 'ports' => $ports])->save();

        return $fw;
    }

    public function payload(ServerFirewall $fw, bool $dryRun, bool $off = false): array
    {
        return [
            'block' => $off ? [] : array_values((array) $fw->block),
            'allow' => $off ? [] : array_values((array) $fw->allow),
            'allow_only' => ! $off && $fw->mode === 'allow_only',
            'ports' => $off ? [] : array_values((array) $fw->ports),
            'never_block' => $this->neverBlock(),
            'confirm_within_seconds' => $dryRun ? 0 : 120,
            'dry_run' => $dryRun,
        ];
    }

    /** Sends the ruleset; dry runs only render it. $off applies an empty, accept-all table. */
    public function apply(Server $server, ?string $userId, bool $dryRun = false, bool $off = false): Job
    {
        if (! $server->isOnline()) {
            throw ApiException::conflict("{$server->name} is not online, so its firewall cannot be changed now.");
        }
        $fw = $this->for($server);
        $job = $this->jobs->create('firewall.apply', ['apply'], null, ['server_id' => $server->id, 'dry_run' => $dryRun, 'off' => $off], $userId, $server->organization_id);
        if (! $dryRun) {
            $fw->update(['status' => 'applying', 'message' => null]);
            app(AuditLogger::class)->log($off ? 'server.firewall.disable' : 'server.firewall.apply', 'servers', $server->id,
                ['server' => $server->name, 'mode' => $fw->mode, 'block' => count((array) $fw->block), 'allow' => count((array) $fw->allow), 'ports' => $fw->ports], $server->organization_id, $userId);
        }
        $this->bus->dispatch($server, 'firewall.apply', $this->payload($fw, $dryRun, $off), $job);

        return $job;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $fw = ServerFirewall::allTenants()->where('server_id', $command->server_id)->first();
        $dry = (bool) ($command->payload['dry_run'] ?? false);
        $off = (bool) ($job->payload['off'] ?? false);
        if ($command->status !== 'succeeded') {
            $dry || $fw?->update(['status' => 'failed', 'message' => (string) $command->message]);
            $this->jobs->fail($job, (string) ($command->message ?: 'The firewall was not applied.'), 'apply');

            return;
        }
        $ruleset = (string) ($command->output['ruleset'] ?? '');
        if ($dry) {
            $fw?->update(['ruleset' => $ruleset]);
        } else {
            // The agent answered after loading the rules, so this server still reaches the panel:
            // confirm, which cancels the automatic rollback.
            if ($server = Server::allTenants()->find($command->server_id)) {
                $c = $this->jobs->create('firewall.confirm', ['confirm'], null, ['server_id' => $server->id], null, $server->organization_id);
                $this->bus->dispatch($server, 'firewall.confirm', [], $c);
            }
            $fw?->update(['status' => $off ? 'disabled' : 'applied', 'enabled' => ! $off, 'ruleset' => $ruleset, 'applied_at' => now(), 'message' => null]);
        }
        $this->jobs->succeed($job, ['ruleset' => $ruleset, 'dry_run' => $dry]);
    }
}
