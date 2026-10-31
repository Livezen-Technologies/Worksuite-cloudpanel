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
    /** PNET-7: how long an unconfirmed SSH lock stays before the agent removes it. */
    public const SSH_CONFIRM_SECONDS = 300;

    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    /**
     * PNET-7: allow SSH to this server only from the private network (and `$allow`).
     * Refused unless an admin device is on the network or another way in is listed,
     * and removed by the agent unless confirmed within SSH_CONFIRM_SECONDS.
     */
    public function lockSsh(Server $server, array $allow, int $port = 22): Server
    {
        if (! $server->mesh_ip || ! $server->mesh_public_key) {
            throw ApiException::conflict("{$server->name} is not on the private network yet.");
        }
        $connected = VpnPeer::whereNull('revoked_at')->where('last_handshake_at', '>=', now()->subMinutes(15))->exists();
        if (! $connected && ! $allow) {
            throw ApiException::conflict('Connect an admin device to the private network first (or list an address that may still use SSH), so you do not lock yourself out.');
        }
        $server->update(['mesh_ssh_lock' => ['allow' => array_values($allow), 'port' => $port, 'pending_until' => now()->addSeconds(self::SSH_CONFIRM_SECONDS)->toIso8601String(), 'confirmed_at' => null]]);
        $this->applyAll();

        return $server->fresh();
    }

    /** The admin reached the server over the VPN: keep the lock. */
    public function confirmSsh(Server $server): Server
    {
        $lock = $server->mesh_ssh_lock ?? throw ApiException::conflict('SSH is not locked on this server.');
        if (! empty($lock['pending_until']) && now()->gt($lock['pending_until'])) {
            $server->update(['mesh_ssh_lock' => null]);
            throw ApiException::conflict('The confirmation window passed and the server removed the lock. Lock it again.');
        }
        $server->update(['mesh_ssh_lock' => array_merge($lock, ['confirmed_at' => now()->toIso8601String(), 'pending_until' => null])]);
        $this->bus->dispatch($server, 'mesh.confirm', []);

        return $server->fresh();
    }

    public function unlockSsh(Server $server): void
    {
        $server->update(['mesh_ssh_lock' => null]);
        $this->applyAll();
    }

    /** Locks left unconfirmed were removed by their agents; forget them here too. */
    public function expireSshLocks(): void
    {
        foreach (Server::allTenants()->whereNotNull('mesh_ssh_lock')->get() as $s) {
            $p = $s->mesh_ssh_lock['pending_until'] ?? null;
            if ($p && empty($s->mesh_ssh_lock['confirmed_at']) && now()->gt($p)) {
                $s->update(['mesh_ssh_lock' => null]);
            }
        }
    }

    private function base(): array
    {
        [$net, $bits] = explode('/', config('platform.mesh.subnet'));

        return [ip2long($net), (int) $bits];
    }

    /**
     * PNET: the IPv6 ULA paired with a mesh IPv4 address: the same host number in
     * MESH_SUBNET6 (10.88.0.3 -> fd88::3). Empty MESH_SUBNET6 turns IPv6 off.
     */
    public function ipv6(?string $v4): ?string
    {
        $cfg = (string) config('platform.mesh.subnet6');
        if ($cfg === '' || ! $v4) {
            return null;
        }
        [$net] = explode('/', $cfg);
        $host = ip2long($v4) - $this->base()[0];

        return inet_ntop(substr((string) inet_pton($net), 0, 12).pack('N', $host));
    }

    private function bits6(): int
    {
        return (int) (explode('/', (string) config('platform.mesh.subnet6'))[1] ?? 64);
    }

    /** PNET-4: the server's /24 of the container supernet (10.89.<n>.0/24 for mesh address x.x.0.<n>). */
    public function containerSubnet(Server $server): ?string
    {
        if (! $server->mesh_ip) {
            return null;
        }
        $n = ip2long($server->mesh_ip) - $this->base()[0];
        [$net] = explode('/', config('platform.mesh.container_supernet'));

        return $n >= 1 && $n <= 254 ? long2ip(ip2long($net) + ($n << 8)).'/24' : null;
    }

    /** PNET-4: a fixed address on dmp-mesh for an app that asks for one (.10 upwards). */
    public function appAddress(\App\Models\Application $app, Server $server): ?string
    {
        $subnet = $this->containerSubnet($server);
        if (! $app->mesh_network || ! $subnet) {
            return null;
        }
        $base = ip2long(explode('/', $subnet)[0]);
        if ($app->mesh_address && (ip2long($app->mesh_address) & 0xFFFFFF00) === $base) {
            return $app->mesh_address;
        }
        $used = \App\Models\Application::allTenants()->where('server_id', $server->id)->whereNotNull('mesh_address')->pluck('mesh_address')->all();
        for ($i = 10; $i < 255; $i++) {
            $ip = long2ip($base + $i);
            if (! in_array($ip, $used, true)) {
                $app->forceFill(['mesh_address' => $ip])->save();

                return $ip;
            }
        }

        return null;
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
        $v6 = [];
        $byServer = $members->keyBy('id');
        foreach (Application::whereIn('server_id', $members->pluck('id'))->get(['name', 'server_id', 'mesh_network', 'mesh_address']) as $app) {
            // PNET-4: an app on dmp-mesh resolves to its own address, others to their server.
            $out[] = ['name' => $app->name.'.'.$zone, 'ip' => $app->mesh_network && $app->mesh_address ? $app->mesh_address : $byServer[$app->server_id]->mesh_ip];
            if (! ($app->mesh_network && $app->mesh_address)) {
                $v6[] = ['name' => $app->name.'.'.$zone, 'server' => $byServer[$app->server_id]->mesh_ip];
            }
        }
        $out = collect($out)->unique('name')->values()->all();
        // AAAA beside A for names that resolve to a server (dmp-mesh containers stay IPv4).
        foreach (array_merge(array_map(fn ($s) => ['name' => Str::slug($s->name).'.'.$zone, 'server' => $s->mesh_ip], $members->all()), $v6) as $r) {
            if ($ip6 = $this->ipv6($r['server'])) {
                $out[] = ['name' => $r['name'], 'ip' => $ip6];
            }
        }

        return collect($out)->unique(fn ($r) => $r['name'].' '.$r['ip'])->values()->all();
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
                'public_key' => $o->mesh_public_key, 'endpoint' => $o->ip.':'.$port,
                'allowed_ips' => array_values(array_filter([$o->mesh_ip.'/32', ($v6 = $this->ipv6($o->mesh_ip)) ? $v6.'/128' : null, $this->containerSubnet($o)])), 'keepalive' => 25,
            ])->values()->all();
            foreach ($vpn as $p) {
                $peers[] = ['public_key' => $p->public_key, 'allowed_ips' => array_values(array_filter([$p->address.'/32', ($v6 = $this->ipv6($p->address)) ? $v6.'/128' : null]))];
            }
            $lock = $server->mesh_ssh_lock;
            $this->bus->dispatch($server, 'mesh.apply', array_filter([
                'address' => $server->mesh_ip.'/'.$bits, 'address6' => ($v6 = $this->ipv6($server->mesh_ip)) ? $v6.'/'.$this->bits6() : null, 'listen_port' => $port, 'peers' => $peers, 'open_port' => true,
                'container_subnet' => $this->containerSubnet($server), 'container_supernet' => $this->containerSubnet($server) ? config('platform.mesh.container_supernet') : null,
                'dns' => ['zone' => config('platform.mesh.zone'), 'image' => config('platform.mesh.dns_image'), 'records' => $records],
                // PNET-7: until confirmed, the lock removes itself after the confirmation window.
                'ssh_lock' => $lock ? ['port' => (int) ($lock['port'] ?? 22), 'allow' => array_values($lock['allow'] ?? []),
                    'confirm_within_seconds' => empty($lock['confirmed_at']) ? self::SSH_CONFIRM_SECONDS : 0] : null,
            ], fn ($v) => $v !== null), $job);
        }

        return $job;
    }

    public function refreshStatus(): int
    {
        $this->expireSshLocks();
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
        $v6 = $this->ipv6($peer->address);
        $lines = ['[Interface]', "PrivateKey = {$privateKey}", 'Address = '.$peer->address.'/32'.($v6 ? ", {$v6}/128" : ''),
            'DNS = '.$members->first()->mesh_ip.', '.config('platform.mesh.zone'), ''];
        foreach ($members as $s) {
            $allowed = implode(', ', array_filter([$s->mesh_ip.'/32', ($v6s = $this->ipv6($s->mesh_ip)) ? $v6s.'/128' : null, $this->containerSubnet($s)]));
            array_push($lines, "# {$s->name}", '[Peer]', "PublicKey = {$s->mesh_public_key}", "Endpoint = {$s->ip}:{$port}", "AllowedIPs = {$allowed}", 'PersistentKeepalive = 25', '');
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
                'id' => $s->id, 'name' => $s->name, 'ip' => $s->ip, 'mesh_ip' => $s->mesh_ip, 'container_subnet' => $this->containerSubnet($s), 'public_key' => $s->mesh_public_key,
                'joined_at' => $s->mesh_joined_at, 'status' => $s->mesh_status, 'online' => $s->isOnline(), 'ssh_lock' => $s->mesh_ssh_lock,
            ])->values(),
            'candidates' => Server::whereNull('mesh_ip')->orderBy('name')->get(['id', 'name', 'ip'])->values(),
            'records' => $this->records(),
            'vpn_peers' => VpnPeer::whereNull('revoked_at')->latest()->get(['id', 'name', 'address', 'public_key', 'user_id', 'last_handshake_at', 'created_at'])->values(),
        ];
    }
}
