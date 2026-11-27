<?php

namespace App\Services;

use App\Models\AgentCommand;
use App\Models\Job;
use App\Models\Server;
use App\Models\VpnNetwork;
use App\Support\Cidr;
use Illuminate\Validation\ValidationException;

/**
 * VPN phase 2: deploys a VPN network to its gateway through the agent (vpn.apply), stops it,
 * and reads its peers back (vpn.status). The gateway makes its own key; only the public key
 * comes back. The agent rolls a failed change back by itself.
 */
class VpnService
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    /** wsv + 12 characters of the id: unique per network, inside Linux's 15-character limit. */
    public function interfaceFor(VpnNetwork $net): string
    {
        return 'wsv'.substr(str_replace('-', '', (string) $net->id), -12);
    }

    /** First usable address of the range. */
    public function gatewayAddress(VpnNetwork $net): string
    {
        [$start] = Cidr::range($net->cidr);

        return long2ip($start + 1).'/'.explode('/', $net->cidr)[1];
    }

    /** What clients may reach: the server mesh and the container range routed over it. */
    public function reach(): array
    {
        return array_values(array_filter([config('platform.mesh.subnet'), config('platform.mesh.container_supernet')]));
    }

    public function deploy(VpnNetwork $net, ?string $userId = null): Job
    {
        if ($net->protocol !== 'wireguard') {
            throw ValidationException::withMessages(['protocol' => 'Deploying OpenVPN networks comes in a later phase; only WireGuard can be deployed now.']);
        }
        $gateway = $net->gateway_server_id ? Server::allTenants()->find($net->gateway_server_id) : null;
        if (! $gateway) {
            throw ValidationException::withMessages(['gateway_server_id' => 'Choose a gateway server first.']);
        }
        if (! $gateway->isOnline()) {
            throw ValidationException::withMessages(['gateway_server_id' => "{$gateway->name} is not online."]);
        }
        $net->update(['interface' => $net->interface ?? $this->interfaceFor($net), 'status' => 'deploying', 'last_error' => null]);
        $job = $this->jobs->create('vpn', ['execute'], $net, ['action' => 'deploy'], $userId, $net->organization_id);
        $this->bus->dispatch($gateway, 'vpn.apply', [
            'interface' => $net->interface, 'address' => $this->gatewayAddress($net), 'listen_port' => (int) $net->port,
            'routing' => $net->routing, 'reach' => $this->reach(), 'peers' => [], 'open_port' => true,
        ], $job);

        return $job;
    }

    public function stop(VpnNetwork $net, ?string $userId = null): ?Job
    {
        $gateway = $net->gateway_server_id ? Server::allTenants()->find($net->gateway_server_id) : null;
        if (! $net->interface || ! $gateway) {
            $net->update(['status' => 'stopped']);

            return null;
        }
        $job = $this->jobs->create('vpn', ['execute'], $net, ['action' => 'stop'], $userId, $net->organization_id);
        $this->bus->dispatch($gateway, 'vpn.apply', ['interface' => $net->interface, 'down' => true], $job);

        return $job;
    }

    public function refresh(VpnNetwork $net): ?Job
    {
        $gateway = $net->gateway_server_id ? Server::allTenants()->find($net->gateway_server_id) : null;
        if (! $net->interface || ! $gateway?->isOnline()) {
            return null;
        }
        $job = $this->jobs->create('vpn', ['execute'], $net, ['action' => 'status'], null, $net->organization_id);
        $this->bus->dispatch($gateway, 'vpn.status', ['interface' => $net->interface], $job);

        return $job;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $net = VpnNetwork::allTenants()->find($job->resource_id);
        $out = (array) ($command->output ?? []);
        if ($net) {
            $down = (bool) (((array) $command->payload)['down'] ?? false);
            if ($command->status !== 'succeeded') {
                $net->update($command->type === 'vpn.status'
                    ? ['gateway_status' => ['up' => false, 'error' => $command->message, 'checked_at' => now()->toIso8601String()]]
                    : ['status' => $down ? $net->status : 'failed', 'last_error' => $command->message]);
            } elseif ($command->type === 'vpn.status') {
                $net->update(['gateway_status' => ['up' => (bool) ($out['up'] ?? false), 'peers' => $out['peers'] ?? [], 'checked_at' => now()->toIso8601String()]]);
            } elseif ($down) {
                $net->update(['status' => 'stopped', 'gateway_status' => null]);
            } else {
                $net->update(['status' => 'active', 'public_key' => $out['public_key'] ?? $net->public_key, 'deployed_at' => now(), 'last_error' => null,
                    'gateway_status' => ['up' => true, 'port_opened' => $out['port_opened'] ?? null, 'checked_at' => now()->toIso8601String()]]);
            }
        }
        $this->bus->genericJobUpdate($job);
    }
}
