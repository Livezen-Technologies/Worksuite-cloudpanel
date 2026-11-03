<?php

namespace App\Services;

use App\Models\Container;
use App\Models\Network;
use App\Models\Server;

/** NET-001..003: network creation and conflict checks. */
class NetworkService
{
    /**
     * The Docker network belonging to one environment on one server.
     *
     * Returns the record, which is only `active` once the agent has confirmed
     * it exists. Callers must not attach a container to it before then: a
     * container referring to a network the host does not have fails to create,
     * and nothing in the deployment pipeline creates networks on demand.
     *
     * Bridge networks are per-host, so an environment whose apps are spread
     * over several servers gets one on each. Cross-host traffic goes over the
     * WireGuard mesh, as it already does.
     */
    public function forEnvironment(\App\Models\Environment $environment, Server $server): Network
    {
        // Unscoped on purpose, and this matters. A deploy is often driven by an
        // agent command result, which runs with no tenant set; a tenant-scoped
        // lookup then finds nothing, tries to create the network again and dies
        // on the (server_id, name) unique index -- a 500 inside the deployment
        // pipeline. One environment on one server is one network globally, so
        // the pair is the identity and the tenant is irrelevant to finding it.
        $network = Network::allTenants()->where('environment_id', $environment->id)->where('server_id', $server->id)->first();
        if ($network) {
            return $network;
        }
        $attributes = [
            // Explicit, for the same reason: with no tenant set the global scope
            // would leave organization_id null and the row would belong to no one.
            'organization_id' => $environment->organization_id,
            'server_id' => $server->id, 'project_id' => $environment->project_id, 'environment_id' => $environment->id,
            'name' => self::environmentNetworkName($environment), 'driver' => 'bridge',
            'internal' => false, 'exposure' => 'private', 'status' => 'creating',
        ];
        try {
            // Its own transaction, which inside an outer one is a savepoint: on PostgreSQL a failed
            // statement aborts the whole transaction, so without it the recovery lookup below fails too.
            $network = \Illuminate\Support\Facades\DB::transaction(fn () => Network::create($attributes));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Two callers can reach this at once -- a deploy and the reconcile
            // both resolve the same (environment, server) pair, and
            // withoutOverlapping does not help because they are different
            // processes. This happened on the live panel: the scheduled
            // reconcile died on networks_server_id_name_unique while a manual
            // run was creating the same row. Losing the race is not an error,
            // so the winner's row is returned and nothing is dispatched twice.
            // Recovered by the key that actually collided, (server_id, name),
            // which is therefore guaranteed to be there -- the pair lookup
            // could miss again if the winner has not finished writing.
            return Network::allTenants()->where('server_id', $server->id)->where('name', $attributes['name'])->firstOrFail();
        }
        $job = app(JobService::class)->create('network.create', ['execute'], $network);
        app(CommandBus::class)->dispatch($server, 'network.create', [
            'name' => $network->name, 'driver' => 'bridge', 'attachable' => true,
            'labels' => ['com.worksuite.environment' => $environment->id, 'com.worksuite.project' => (string) $environment->project_id],
        ], $job);

        return $network;
    }

    /**
     * The first agent that understands `network.connect`. A server reporting
     * less than this cannot attach the ingress, so nothing is asked of it.
     */
    public const MIN_AGENT_FOR_CONNECT = '1.2.5';

    /** Whether this server's agent is new enough to attach a network. */
    public static function agentCanConnect(Server $server): bool
    {
        $v = (string) $server->agent_version;

        // An unknown version is treated as too old: a command that cannot
        // succeed is worse than an attach that happens one reconcile later.
        return $v !== '' && version_compare($v, self::MIN_AGENT_FOR_CONNECT, '>=');
    }

    /**
     * Put the ingress Traefik on an environment's network.
     *
     * This ingress runs with no docker provider: routers come from the file
     * provider and name the container (`http://app-foo:80`), so Traefik can
     * only reach a container over a Docker network it shares with it. Until
     * Traefik is on the environment network, `proxy` is the only thing making
     * any of those upstreams resolve -- which is why nothing may be taken off
     * `proxy` before this has succeeded.
     *
     * Attaching is additive and idempotent: it changes no routing, and the
     * agent treats "already attached" as success, so it is safe to re-run.
     *
     * @return int the number of ingress servers the attach was dispatched to
     */
    public function attachIngress(Network $network, bool $force = false): int
    {
        $container = (string) config('platform.traefik.container');
        if ($container === '' || ! $network->environment_id) {
            return 0;
        }
        // Already confirmed on it: re-attaching every reconcile would queue a
        // command every fifteen minutes for ever and tell us nothing new.
        if ($network->ingress_attached_at && ! $force) {
            return 0;
        }
        $dispatched = 0;
        foreach (app(TraefikConfigService::class)->ingressServers() as $server) {
            // Bridge networks are per-host: an ingress elsewhere cannot join
            // this network, and reaches the app over the mesh as it does now.
            if ($server->id !== $network->server_id) {
                continue;
            }
            // An older agent has no network.connect and would fail the command
            // on every reconcile for ever. Skipping keeps the panel safe to
            // deploy before the fleet is updated; the attach happens by itself
            // on the first reconcile after the agent reports a new enough
            // version, so no one has to come back and trigger it.
            if (! self::agentCanConnect($server)) {
                continue;
            }
            app(CommandBus::class)->dispatch($server, 'network.connect', [
                'network' => $network->name, 'container' => $container,
            ]);
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Deterministic, and the whole id on purpose.
     *
     * The first twelve hex characters of a UUIDv7 are exactly its 48-bit
     * millisecond timestamp, so a prefix is not an identifier: two
     * environments created in the same millisecond -- which is what creating a
     * project with its first environment, or duplicating one, does in a single
     * transaction -- produced the *same* network name and collided on the
     * (server_id, name) unique index. The full id is 41 characters with the
     * prefix, well inside Docker's limit, and the bridge interface is named
     * from the network's Docker id rather than this, so length costs nothing.
     *
     * The customer's project name is deliberately not used: it would put their
     * naming on a shared host.
     */
    public static function environmentNetworkName(\App\Models\Environment $environment): string
    {
        return 'wscp-env-'.str_replace('-', '', (string) $environment->id);
    }

    /** @return array{ok: bool, conflicts: array, warnings: array} */
    public function check(Server $server, array $input): array
    {
        $conflicts = [];
        $warnings = [];
        if (! empty($input['name']) && Network::where('server_id', $server->id)->where('name', $input['name'])->exists()) {
            $conflicts[] = ['type' => 'name', 'message' => "A network named {$input['name']} already exists on {$server->name}."];
        }
        if (! empty($input['subnet'])) {
            if (! self::validCidr($input['subnet'])) {
                $conflicts[] = ['type' => 'subnet', 'message' => "{$input['subnet']} is not a valid IPv4 CIDR."];
            } else {
                foreach (Network::where('server_id', $server->id)->whereNotNull('subnet')->get() as $network) {
                    if (self::validCidr($network->subnet) && self::overlaps($input['subnet'], $network->subnet)) {
                        $conflicts[] = ['type' => 'ip', 'message' => "Subnet {$input['subnet']} overlaps {$network->subnet} ({$network->name})."];
                    }
                }
            }
        }
        foreach ((array) ($input['ports'] ?? []) as $port) {
            $port = (int) (is_array($port) ? ($port['host_port'] ?? 0) : $port);
            if (! $port) {
                continue;
            }
            $used = Container::where('server_id', $server->id)->get()->first(fn ($c) => collect($c->ports ?? [])->contains(fn ($p) => (int) preg_replace('/^(?:[\d.]+:)?(\d+).*$/', '$1', (string) $p) === $port || str_contains((string) $p, ":{$port}->") || str_starts_with((string) $p, "{$port}:")));
            if ($used) {
                $conflicts[] = ['type' => 'port', 'message' => "Host port {$port} is already used by {$used->name}."];
            }
            if ($port < 1024 && ! in_array($port, [80, 443], true)) {
                $warnings[] = "Port {$port} is privileged.";
            }
            $warnings[] = "Host port {$port} will be publicly exposed unless bound to 127.0.0.1.";
        }
        if (($input['driver'] ?? 'bridge') === 'host') {
            $warnings[] = 'Host networking removes network isolation and needs approval.';
        }

        return ['ok' => $conflicts === [], 'conflicts' => $conflicts, 'warnings' => array_values(array_unique($warnings))];
    }

    public static function validCidr(string $cidr): bool
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m)) {
            return false;
        }

        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (int) $m[2] <= 32;
    }

    public static function overlaps(string $a, string $b): bool
    {
        [$ipA, $maskA] = explode('/', $a);
        [$ipB, $maskB] = explode('/', $b);
        $mask = min((int) $maskA, (int) $maskB);
        $bits = $mask === 0 ? 0 : (~0 << (32 - $mask)) & 0xFFFFFFFF;

        return (ip2long($ipA) & $bits) === (ip2long($ipB) & $bits);
    }
}
