<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Job;
use App\Models\Server;
use App\Models\User;
use App\Models\VpnPeer;
use Illuminate\Support\Str;

/**
 * Private network (PNET-1..5): a WireGuard full mesh between an organisation's
 * servers, `*.internal` names served by CoreDNS on each server's mesh address,
 * and WireGuard configs for administrators' devices.
 *
 * Servers generate their own keys; the panel only ever sees public keys. A
 * VPN peer's private key is generated here, put into the config shown once,
 * and not stored. Servers get addresses from the bottom of the subnet
 * (x.x.0.1…), VPN peers from the top (x.x.255.1…).
 */
class MeshService
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    private function base(): array
    {
        [$net, $bits] = explode('/', config('platform.mesh.subnet'));

        return [ip2long($net), (int) $bits];
    }

    public function members()
    {
        return Server::whereNotNull('mesh_ip')->orderBy('mesh_ip')->get();
    }

    private function nextAddress(bool $vpn): string
    {
        [$net, $bits] = $this->base();
        $used = array_merge(Server::allTenants()->where('organization_id', \App\Support\Tenant::current()?->id)->whereNotNull('mesh_ip')->pluck('mesh_ip')->all(),
            VpnPeer::whereNull('revoked_at')->pluck('address')->all());
        $size = 2 ** (32 - $bits);
        for ($i = 1; $i < $size - 1; $i++) {
            $ip = long2ip($vpn ? $net + $size - 1 - $i : $net + $i);
            if (str_ends_with($ip, '.0') || str_ends_with($ip, '.255') || in_array($ip, $used, true)) {
                continue;
            }

            return $ip;
        }
        throw ApiException::conflict('The private network subnet is full.');
    }

    public function join(Server $server): Server
    {
        if ($server->mesh_ip) {
            throw ApiException::conflict("{$server->name} is already on the private network.");
        }
        if (! $server->ip) {
            throw ApiException::validation(['ip' => ["{$server->name} has no public IP for its WireGuard endpoint."]]);
        }
        $server->update(['mesh_ip' => $this->nextAddress(false), 'mesh_joined_at' => now()]);
        $this->applyAll();

        return $server->fresh();
    }

    public function leave(Server $server): void
    {
        if ($server->isOnline()) {
            $this->bus->dispatch($server, 'mesh.apply', ['down' => true]);
        }
        $server->update(['mesh_ip' => null, 'mesh_public_key' => null, 'mesh_joined_at' => null, 'mesh_status' => null]);
        $this->applyAll();
    }

    /** PNET-2: <server>.internal and <app>.internal → the mesh address that serves them. */
    public function records(): array
    {
        $zone = config('platform.mesh.zone');
        $members = $this->members();
        $out = [];
        foreach ($members as $s) {
            $out[] = ['name' => Str::slug($s->name).'.'.$zone, 'ip' => $s->mesh_ip];
        }
        $byServer = $members->keyBy('id');
        foreach (Application::whereIn('server_id', $members->pluck('id'))->get(['name', 'server_id']) as $app) {
            $out[] = ['name' => $app->name.'.'.$zone, 'ip' => $byServer[$app->server_id]->mesh_ip];
        }

        return collect($out)->unique('name')->values()->all();
    }

    /** Pushes the current peer list and DNS records to every member (PNET-1/2/4). */
    public function applyAll(): ?Job
    {
        $members = $this->members();
        if ($members->isEmpty()) {
            return null;
        }
        $bits = $this->base()[1];
        $port = (int) config('platform.mesh.port');
        $records = $this->records();
        $vpn = VpnPeer::whereNull('revoked_at')->get();
        $job = $this->jobs->create('mesh', ['execute'], null, ['members' => $members->count()]);
        foreach ($members as $server) {
            if (! $server->isOnline()) {
                continue;
            }
            $peers = $members->where('id', '!=', $server->id)->whereNotNull('mesh_public_key')->map(fn ($o) => [
                'public_key' => $o->mesh_public_key, 'endpoint' => $o->ip.':'.$port, 'allowed_ips' => [$o->mesh_ip.'/32'], 'keepalive' => 25,
            ])->values()->all();
            foreach ($vpn as $p) {
                $peers[] = ['public_key' => $p->public_key, 'allowed_ips' => [$p->address.'/32']];
            }
            $this->bus->dispatch($server, 'mesh.apply', [
                'address' => $server->mesh_ip.'/'.$bits, 'listen_port' => $port, 'peers' => $peers,
                'dns' => ['zone' => config('platform.mesh.zone'), 'image' => config('platform.mesh.dns_image'), 'records' => $records],
            ], $job);
        }

        return $job;
    }

    public function refreshStatus(): int
    {
        $n = 0;
        foreach (Server::allTenants()->whereNotNull('mesh_ip')->get() as $server) {
            if ($server->isOnline()) {
                $job = $this->jobs->create('mesh', ['execute'], $server, ['status' => true], null, $server->organization_id);
                $this->bus->dispatch($server, 'mesh.status', [], $job);
                $n++;
            }
        }

        return $n;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $server = Server::allTenants()->find($command->server_id);
        $out = (array) ($command->output ?? []);
        if ($command->status !== 'succeeded') {
            $server?->update(['mesh_status' => ['up' => false, 'error' => $command->message, 'checked_at' => now()->toIso8601String()]]);
            $this->bus->genericJobUpdate($job);

            return;
        }
        if ($command->type === 'mesh.status') {
            $server?->update(['mesh_status' => ['up' => (bool) ($out['up'] ?? false), 'peers' => $out['peers'] ?? [], 'checked_at' => now()->toIso8601String()]]);
            foreach ((array) ($out['peers'] ?? []) as $p) {
                if (! empty($p['last_handshake'])) {
                    VpnPeer::allTenants()->where('organization_id', $server?->organization_id)->where('public_key', $p['public_key'] ?? '')
                        ->where(fn ($q) => $q->whereNull('last_handshake_at')->orWhere('last_handshake_at', '<', $p['last_handshake']))
                        ->update(['last_handshake_at' => \Illuminate\Support\Carbon::parse($p['last_handshake'])]);
                }
            }
        } elseif (! empty($out['public_key']) && $server && $server->mesh_ip && $server->mesh_public_key !== $out['public_key']) {
            // A new or changed key: every other member has to learn it.
            $server->update(['mesh_public_key' => $out['public_key'], 'mesh_status' => ['up' => true, 'dns_error' => $out['dns_error'] ?? null, 'checked_at' => now()->toIso8601String()]]);
            app(\App\Support\Tenant::class)->as($server->organization_id, fn () => $this->applyAll());
        } elseif ($server) {
            $server->update(['mesh_status' => array_merge((array) $server->mesh_status, ['up' => true, 'dns_error' => $out['dns_error'] ?? null])]);
        }
        $this->bus->genericJobUpdate($job);
    }

    /** PNET-3: a device config for an administrator; the private key exists only in the returned text. */
    public function addPeer(string $name, ?User $user): array
    {
        if ($this->members()->whereNotNull('mesh_public_key')->isEmpty()) {
            throw ApiException::conflict('Add a server to the private network first.');
        }
        $pair = sodium_crypto_box_keypair();
        $private = base64_encode(sodium_crypto_box_secretkey($pair));
        $public = base64_encode(sodium_crypto_box_publickey($pair));
        $peer = VpnPeer::create(['user_id' => $user?->id, 'name' => $name, 'public_key' => $public, 'address' => $this->nextAddress(true)]);
        $this->applyAll();

        return ['peer' => $peer, 'config' => $this->clientConfig($peer, $private)];
    }

    public function clientConfig(VpnPeer $peer, string $privateKey): string
    {
        $members = $this->members()->whereNotNull('mesh_public_key');
        $port = (int) config('platform.mesh.port');
        $lines = ['[Interface]', "PrivateKey = {$privateKey}", "Address = {$peer->address}/32",
            'DNS = '.$members->first()->mesh_ip.', '.config('platform.mesh.zone'), ''];
        foreach ($members as $s) {
            array_push($lines, "# {$s->name}", '[Peer]', "PublicKey = {$s->mesh_public_key}", "Endpoint = {$s->ip}:{$port}", "AllowedIPs = {$s->mesh_ip}/32", 'PersistentKeepalive = 25', '');
        }

        return implode("\n", $lines);
    }

    public function revoke(VpnPeer $peer): void
    {
        $peer->update(['revoked_at' => now()]);
        $this->applyAll();
    }

    public function overview(): array
    {
        return [
            'subnet' => config('platform.mesh.subnet'), 'port' => (int) config('platform.mesh.port'), 'zone' => config('platform.mesh.zone'),
            'members' => $this->members()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'ip' => $s->ip, 'mesh_ip' => $s->mesh_ip, 'public_key' => $s->mesh_public_key,
                'joined_at' => $s->mesh_joined_at, 'status' => $s->mesh_status, 'online' => $s->isOnline(),
            ])->values(),
            'candidates' => Server::whereNull('mesh_ip')->orderBy('name')->get(['id', 'name', 'ip'])->values(),
            'records' => $this->records(),
            'vpn_peers' => VpnPeer::whereNull('revoked_at')->latest()->get(['id', 'name', 'address', 'public_key', 'user_id', 'last_handshake_at', 'created_at'])->values(),
        ];
    }
}
