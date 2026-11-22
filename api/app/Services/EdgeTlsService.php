<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Container;
use App\Models\EdgeTlsCert;
use App\Models\Job;
use App\Models\Release;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * XNODE-3b: verified TLS on the edge → app-node hop.
 *
 * - The panel keeps an internal CA (P-256, key stored with the `encrypted` cast) and issues one
 *   server certificate per app node: SAN = DNS:<server id>.edge.internal + IP:<public ip>.
 * - Each app node that hosts edge-routed apps runs one terminator, `ws-edge-tls` (Caddy), on the
 *   `proxy` network. It publishes one TLS host port per app (source.edge_tls_port, filtered by
 *   edge.ports exactly like the plain port) and proxies to app-<name>:<port> locally. Its config,
 *   certificate and key live in the `ws-edge-tls` volume, written with fs.write; Caddy runs with
 *   --watch, so route and certificate changes reload gracefully. Only a change in the set of
 *   ports needs the container recreated (Docker cannot add a published port).
 * - Edges send https://<node ip>:<tls port> through a serversTransport with serverName and the
 *   CA inline in rootCAs (Traefik reads PEM content there), never insecureSkipVerify.
 * - An edge switches an app to https only after the agent confirmed the terminator for that
 *   port (live_ports), so turning the flag on is zero-downtime for a running app.
 */
class EdgeTlsService implements HandlesCommandResults
{
    public const CONTAINER = 'ws-edge-tls';

    public const VOLUME = 'ws-edge-tls';

    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    public static function serverName(Server $home): string
    {
        return $home->id.'.edge.internal';
    }

    public static function transportName(Server $home): string
    {
        return 'dmp-edge-tls-'.$home->id;
    }

    // ---- CA and certificates -------------------------------------------------------------

    public function ca(): EdgeTlsCert
    {
        if ($ca = EdgeTlsCert::where('kind', 'ca')->first()) {
            return $ca;
        }
        $key = $this->newKey();
        [$pem, $serial] = $this->sign(['commonName' => 'Livezen Cloud edge CA'], $key, null, null, 3650, 'v3_ca', null);
        openssl_pkey_export($key, $keyPem);

        return EdgeTlsCert::create(['kind' => 'ca', 'cert_pem' => $pem, 'key_encrypted' => $keyPem, 'serial' => $serial, 'not_after' => $this->notAfter($pem)]);
    }

    /** The node's certificate, issued when missing, due for renewal or for another IP. */
    public function nodeCert(Server $home): EdgeTlsCert
    {
        $row = EdgeTlsCert::where('kind', 'node')->where('server_id', $home->id)->first();
        if ($row && ! $this->due($row) && $row->san_ip === (string) $home->ip) {
            return $row;
        }

        return $this->issue($home, $row);
    }

    public function due(EdgeTlsCert $row): bool
    {
        return $row->not_after->lte(now()->addDays((int) config('platform.edge.tls_renew_days', 30)));
    }

    public function issue(Server $home, ?EdgeTlsCert $row = null): EdgeTlsCert
    {
        if (! $home->ip) {
            throw new RuntimeException("{$home->name} has no public IP");
        }
        $ca = $this->ca();
        $caKey = openssl_pkey_get_private($ca->key_encrypted);
        $key = $this->newKey();
        $san = 'DNS:'.self::serverName($home).','.'IP:'.$home->ip;
        [$pem, $serial] = $this->sign(['commonName' => self::serverName($home)], $key, $ca->cert_pem, $caKey, (int) config('platform.edge.tls_cert_days', 365), 'v3_leaf', $san);
        openssl_pkey_export($key, $keyPem);
        $attrs = ['kind' => 'node', 'server_id' => $home->id, 'cert_pem' => $pem, 'key_encrypted' => $keyPem, 'serial' => $serial, 'san_ip' => (string) $home->ip, 'not_after' => $this->notAfter($pem)];
        if ($row) {
            $row->update($attrs);
        } else {
            $row = EdgeTlsCert::create($attrs);
        }

        return $row;
    }

    private function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (! $key) {
            throw new RuntimeException('openssl: cannot generate a P-256 key');
        }

        return $key;
    }

    /** @return array{0: string, 1: string} PEM and hex serial */
    private function sign(array $dn, \OpenSSLAsymmetricKey $key, ?string $caPem, $caKey, int $days, string $ext, ?string $san): array
    {
        $cnf = tempnam(sys_get_temp_dir(), 'edgetls');
        file_put_contents($cnf, implode("\n", [
            '[req]', 'distinguished_name = dn', '[dn]',
            '[v3_ca]', 'basicConstraints = critical,CA:TRUE,pathlen:0', 'keyUsage = critical,keyCertSign,cRLSign', 'subjectKeyIdentifier = hash',
            '[v3_leaf]', 'basicConstraints = critical,CA:FALSE', 'keyUsage = critical,digitalSignature', 'extendedKeyUsage = serverAuth',
            'subjectKeyIdentifier = hash', 'authorityKeyIdentifier = keyid', 'subjectAltName = '.($san ?? 'DNS:invalid'), '',
        ]));
        try {
            $opts = ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => $ext];
            $csr = openssl_csr_new($dn, $key, $opts);
            $serial = random_int(1, PHP_INT_MAX);
            $cert = openssl_csr_sign($csr, $caPem, $caKey ?? $key, $days, $opts, $serial);
            if (! $cert || ! openssl_x509_export($cert, $pem)) {
                throw new RuntimeException('openssl: signing failed: '.openssl_error_string());
            }
        } finally {
            @unlink($cnf);
        }

        return [$pem, dechex($serial)];
    }

    private function notAfter(string $pem): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::createFromTimestamp(openssl_x509_parse($pem)['validTo_time_t']);
    }

    // ---- Edge side -----------------------------------------------------------------------

    /** The serversTransport an edge uses for $home: verified against the panel CA, by serverName. */
    public function transport(Server $home): array
    {
        return ['serverName' => self::serverName($home), 'rootCAs' => [$this->ca()->cert_pem]];
    }

    /** True once the agent confirmed $home's terminator serves this app's TLS port. */
    public function live(Application $app): bool
    {
        $row = EdgeTlsCert::where('kind', 'node')->where('server_id', $app->server_id)->first();

        return $row && in_array(app(EdgeRoutingService::class)->tlsPort($app), array_map('intval', (array) $row->live_ports), true);
    }

    /** True while the app's current container still publishes the plain host port (migration). */
    public function plainStillPublished(Application $app): bool
    {
        $port = (int) ($app->source['edge_port'] ?? 0);

        return $port && Container::allTenants()->where('application_id', $app->id)->where('server_id', $app->server_id)
            ->whereNotIn('state', ['removing', 'removed'])->get()
            ->contains(fn ($c) => collect((array) ($c->spec['ports'] ?? []))->contains(fn ($p) => (int) ($p['host_port'] ?? 0) === $port));
    }

    // ---- Node side -----------------------------------------------------------------------

    /** @return list<array{port: int, upstream: string}> */
    public function routes(Server $home): array
    {
        $routing = app(EdgeRoutingService::class);
        $out = [];
        foreach ($routing->routedApps($home) as $app) {
            $port = Release::find($app->current_release_id)?->config['port'] ?? $app->port;
            if ($port) {
                $out[] = ['port' => $routing->tlsPort($app), 'upstream' => $app->containerName().':'.(int) $port];
            }
        }
        usort($out, fn ($a, $b) => $a['port'] <=> $b['port']);

        return $out;
    }

    public function caddyfile(Server $home, EdgeTlsCert $cert, array $routes): string
    {
        $trusted = collect(app(EdgeRoutingService::class)->firewallFor($home)['rules'])->pluck('allow')->flatten()->unique()->sort()->values()
            ->map(fn ($ip) => str_contains($ip, '/') ? $ip : (str_contains($ip, ':') ? "{$ip}/128" : "{$ip}/32"))->all();
        $crt = "/etc/caddy/node-{$cert->serial}.crt";
        $key = "/etc/caddy/node-{$cert->serial}.key";
        $out = "# Managed by Docker Manager Portal (XNODE-3b ws-edge-tls). Do not edit. cert {$cert->serial}\n{\n\tauto_https off\n";
        if ($trusted) {
            $out .= "\tservers {\n\t\ttrusted_proxies static ".implode(' ', $trusted)."\n\t}\n";
        }
        $out .= "}\n";
        foreach ($routes as $r) {
            $out .= "\nhttps://:{$r['port']} {\n\ttls {$crt} {$key}\n\treverse_proxy {$r['upstream']}\n}\n";
        }

        return $out;
    }

    /** Writes the terminator's files on $home and (re)creates it when its port set changed. Returns whether anything was sent. */
    public function syncNode(Server $home, bool $force = false): bool
    {
        if (! EdgeRoutingService::tlsEnabled() || ! $home->ip || ! EdgeRoutingService::offIngress($home)) {
            return false;
        }
        $routes = $this->routes($home);
        $existing = EdgeTlsCert::where('kind', 'node')->where('server_id', $home->id)->first();
        if (! $routes && ! $existing) {
            return false;
        }
        $cert = $this->nodeCert($home);
        $ports = array_column($routes, 'port');
        $caddyfile = $this->caddyfile($home, $cert, $routes);
        $hash = hash('sha256', json_encode([$caddyfile, $cert->serial, $ports]));
        $cacheKey = 'edge-tls:'.$home->id;
        if (! $force && Cache::get($cacheKey) === $hash) {
            return false;
        }
        $present = Container::allTenants()->where('server_id', $home->id)->where('name', self::CONTAINER)->whereNotIn('state', ['removing', 'removed'])->first();
        $recreate = ! $present || array_map('intval', (array) $cert->ports) !== $ports;

        $job = $this->jobs->create('edge.tls', ['execute'], $home, ['ports' => $ports, 'serial' => $cert->serial, 'recreate' => $recreate], null, $home->organization_id);
        // The key file is create-only, so it is sent once per serial: once the node has confirmed
        // it, later syncs (route changes, a forced resync) must not send it again or they fail.
        $sendKey = $cert->key_delivered_serial !== $cert->serial;
        foreach ($this->fileCommands($cert, $caddyfile, $sendKey) as [$type, $payload]) {
            $this->bus->dispatch($home, $type, $payload, $job);
        }
        if ($recreate) {
            if ($present) {
                $this->bus->dispatch($home, 'container.remove', ['container_id' => self::CONTAINER, 'keep_volumes' => true, 'force' => true], $job);
            }
            $spec = $this->spec($ports);
            $this->bus->dispatch($home, 'container.create', $spec, $job);
            $present ? $present->update(['state' => 'creating', 'spec' => ContainerSpec::redact($spec)])
                : Container::allTenants()->create(['server_id' => $home->id, 'name' => self::CONTAINER, 'image' => $spec['image'], 'state' => 'creating', 'spec' => ContainerSpec::redact($spec)]);
            $cert->update(['ports' => $ports]);
        }
        Cache::put($cacheKey, $hash, now()->addDays(30));

        return true;
    }

    /**
     * The key is written to a new, serial-named file (create=true) so fs.write never snapshots
     * an old key into the agent's file history; the agent's UMask=0077 makes files 0600, and the
     * explicit chmods below make the result independent of it (Caddy runs as root).
     *
     * @return list<array{0: string, 1: array}>
     */
    public function fileCommands(EdgeTlsCert $cert, string $caddyfile, bool $includeKey = true): array
    {
        $v = self::VOLUME;
        $crt = "node-{$cert->serial}.crt";
        $key = "node-{$cert->serial}.key";

        return array_merge(
            [
                ['volume.create', ['name' => $v, 'driver' => 'local', 'labels' => (object) ['io.dockermgr.managed' => 'true']]],
                ['fs.write', ['volume' => $v, 'path' => $crt, 'content' => $cert->cert_pem.$this->ca()->cert_pem]],
            ],
            $includeKey ? [
                ['fs.write', ['volume' => $v, 'path' => $key, 'content' => $cert->key_encrypted, 'create' => true]],
                ['fs.chmod', ['volume' => $v, 'paths' => [$key], 'mode' => '0600']],
            ] : [],
            [
                // The Caddyfile last: --watch reloads on it, by which time the files it names exist.
                ['fs.write', ['volume' => $v, 'path' => 'Caddyfile', 'content' => $caddyfile]],
                ['fs.chmod', ['volume' => $v, 'paths' => [$crt, 'Caddyfile'], 'mode' => '0644']],
            ],
        );
    }

    public function spec(array $ports): array
    {
        return ContainerSpec::build([
            'name' => self::CONTAINER, 'image' => (string) config('platform.edge.tls_image', 'caddy:2.8.4-alpine'),
            'cmd' => ['caddy', 'run', '--config', '/etc/caddy/Caddyfile', '--adapter', 'caddyfile', '--watch'],
            'ports' => array_map(fn ($p) => ['container_port' => $p, 'host_port' => $p], $ports),
            'network' => config('platform.traefik.network', 'proxy'),
            'mounts' => [['source' => self::VOLUME, 'target' => '/etc/caddy', 'read_only' => true]],
            'read_only_rootfs' => true, 'tmpfs' => ['/data', '/config', '/tmp'],
            'cap_add' => ['NET_BIND_SERVICE'], 'memory_mb' => 128,
            'labels' => ['io.dockermgr.role' => 'edge-tls'],
        ], null);
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $commands = AgentCommand::allTenants()->where('job_id', $job->id)->get();
        if ($commands->contains(fn ($c) => ! $c->isFinished())) {
            $this->jobs->progress($job, (int) floor($commands->filter->isFinished()->count() / max(1, $commands->count()) * 100));

            return;
        }
        if ($failed = $commands->firstWhere('status', 'failed')) {
            Cache::forget('edge-tls:'.$job->resource_id);
            $this->jobs->fail($job, $failed->message ?: 'ws-edge-tls update failed', 'execute');

            return;
        }
        EdgeTlsCert::where('kind', 'node')->where('server_id', $job->resource_id)->update(array_filter([
            'live_ports' => json_encode($job->payload['ports'] ?? []),
            // Every command succeeded, so the key for this serial is on the node now.
            'key_delivered_serial' => $job->payload['serial'] ?? null,
        ], fn ($v) => $v !== null));
        $this->jobs->succeed($job, ['ports' => $job->payload['ports'] ?? []]);
        // Now the edges may switch these ports to https.
        app(TraefikConfigService::class)->push();
    }

    public function syncAll(bool $force = false): int
    {
        if (! EdgeRoutingService::tlsEnabled()) {
            return 0;
        }
        $n = 0;
        foreach (Server::allTenants()->get() as $s) {
            $n += (int) $this->syncNode($s, $force);
        }

        return $n;
    }

    /** Scheduled: renews node certificates within tls_renew_days of expiry and pushes them. */
    public function renewDue(): array
    {
        $renewed = [];
        foreach (EdgeTlsCert::where('kind', 'node')->get() as $row) {
            $home = Server::allTenants()->find($row->server_id);
            if (! $home || ! $this->due($row)) {
                continue;
            }
            $this->issue($home, $row);
            if (EdgeRoutingService::tlsEnabled()) {
                $this->syncNode($home, true);
            }
            $renewed[] = $home->name;
        }

        return $renewed;
    }
}
