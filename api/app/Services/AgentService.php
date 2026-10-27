<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCertificate;
use App\Models\AgentEvent;
use App\Models\Container;
use App\Models\EnrollmentToken;
use App\Models\Image;
use App\Models\Metric;
use App\Models\Network;
use App\Models\Server;
use App\Models\Volume;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Agent protocol (SRS 6.4, docs/API.md). */
class AgentService
{
    public function __construct(private CommandBus $bus, private AuditLogger $audit) {}

    public function enroll(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $enrollment = EnrollmentToken::allTenants()->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            if (! $enrollment || $enrollment->used_at || $enrollment->expires_at->isPast()) {
                throw new ApiException('unauthenticated', 'The enrolment token is invalid, expired or already used. Generate a new one in the panel.', 401);
            }
            $enrollment->update(['used_at' => now()]);
            $server = Server::allTenants()->findOrFail($enrollment->server_id);
            $facts = $data['facts'] ?? [];
            $server->update(array_filter([
                'hostname' => $data['hostname'] ?? null,
                'os' => $facts['os'] ?? null,
                'kernel' => $facts['kernel'] ?? null,
                'docker_version' => $facts['docker_version'] ?? null,
                'cpu_cores' => $facts['cpu_cores'] ?? null,
                'ram_mb' => $facts['ram_mb'] ?? null,
                'disk_gb' => $facts['disk_gb'] ?? null,
                'arch' => $facts['arch'] ?? null,
                'ip' => $server->ip ?: request()?->ip(),
            ], fn ($v) => $v !== null));

            $secret = Str::random(64);
            $hmacKey = bin2hex(random_bytes(32));
            Agent::allTenants()->where('server_id', $server->id)->delete();
            $agent = Agent::create([
                'organization_id' => $server->organization_id,
                'server_id' => $server->id,
                'secret_hash' => hash('sha256', $secret),
                'hmac_key' => $hmacKey,
                'enrolled_at' => now(),
            ]);
            $certificate = ! empty($data['csr']) ? $this->signCsr($agent, $data['csr']) : null;
            $this->audit->log('agent.enrolled', 'servers', $server->id, ['hostname' => $server->hostname], $server->organization_id, null, 'agent');

            return array_filter([
                'server_id' => $server->id,
                'agent_secret' => $secret,
                'hmac_key' => $hmacKey,
                'heartbeat_interval' => config('platform.heartbeat_interval'),
                'certificate' => $certificate,
            ], fn ($v) => $v !== null);
        });
    }

    /** Heartbeat: store stats, reconcile inventory, return signed pending commands. */
    public function heartbeat(Agent $agent, array $data): array
    {
        $server = Server::allTenants()->findOrFail($agent->server_id);
        $stats = $data['stats'] ?? [];
        $now = now();

        DB::transaction(function () use ($server, $agent, $data, $stats, $now) {
            $wasOffline = in_array($server->status, ['offline', 'pending'], true);
            $server->fill([
                'agent_version' => $data['agent_version'] ?? $server->agent_version,
                'cpu_pct' => $stats['cpu_pct'] ?? null,
                'ram_pct' => $stats['ram_pct'] ?? null,
                'disk_pct' => $stats['disk_pct'] ?? null,
                'net_rx' => $stats['net_rx'] ?? null,
                'net_tx' => $stats['net_tx'] ?? null,
                'docker_error' => $data['docker_error'] ?? null,
                'last_heartbeat_at' => $now,
            ])->save();
            $agent->update(['last_seen_at' => $now, 'version' => $data['agent_version'] ?? $agent->version]);
            if ($wasOffline) {
                AgentEvent::create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'type' => 'connected', 'data' => ['agent_version' => $data['agent_version'] ?? null], 'created_at' => $now]);
            }

            Metric::create([
                'organization_id' => $server->organization_id, 'server_id' => $server->id,
                'cpu_pct' => $stats['cpu_pct'] ?? null, 'ram_pct' => $stats['ram_pct'] ?? null, 'disk_pct' => $stats['disk_pct'] ?? null,
                'net_rx' => $stats['net_rx'] ?? null, 'net_tx' => $stats['net_tx'] ?? null, 'recorded_at' => $now,
            ]);

            // null lists mean "Docker unreachable": keep the last known inventory.
            if (is_array($data['containers'] ?? null)) {
                $this->reconcileContainers($server, $data['containers'], $now);
            }
            if (is_array($data['images'] ?? null)) {
                $this->reconcileImages($server, $data['images']);
            }
            if (is_array($data['networks'] ?? null)) {
                $this->reconcileNetworks($server, $data['networks']);
            }
            if (is_array($data['volumes'] ?? null)) {
                $this->reconcileVolumes($server, $data['volumes']);
            }
        });

        return ['commands' => $this->bus->deliver($server, $agent->hmac_key)];
    }

    private function reconcileContainers(Server $server, array $reported, Carbon $now): void
    {
        $seen = [];
        foreach ($reported as $c) {
            if (empty($c['docker_id'])) {
                continue;
            }
            $seen[] = $c['docker_id'];
            $name = ltrim($c['name'] ?? $c['docker_id'], '/');
            $row = Container::allTenants()->where('server_id', $server->id)
                ->where(fn ($q) => $q->where('docker_id', $c['docker_id'])->orWhere(fn ($q) => $q->whereNull('docker_id')->where('name', $name)))
                ->first() ?? new Container(['organization_id' => $server->organization_id, 'server_id' => $server->id]);
            $state = $c['state'] ?? 'unknown';
            $row->fill([
                'docker_id' => $c['docker_id'],
                'name' => $name,
                'image' => $c['image'] ?? '',
                'state' => $state,
                'health' => ($c['health'] ?? '') ?: null,
                'cpu_pct' => $c['cpu_pct'] ?? null,
                'mem_mb' => $c['mem_mb'] ?? null,
                'ports' => $c['ports'] ?? [],
                'docker_created_at' => isset($c['created']) ? $this->time($c['created']) : null,
            ]);
            if ($state === 'running' && ($row->getOriginal('state') !== 'running' || ! $row->started_at)) {
                $row->started_at = $now;
            }
            if (! $row->application_id && str_starts_with($name, 'app-')) {
                $row->application_id = \App\Models\Application::allTenants()->where('organization_id', $server->organization_id)->where('name', substr($name, 4))->value('id');
            }
            $row->save();
            if ($state === 'running' && $row->cpu_pct !== null) {
                Metric::create(['organization_id' => $server->organization_id, 'server_id' => $server->id, 'container_id' => $row->id, 'cpu_pct' => $row->cpu_pct, 'mem_mb' => $row->mem_mb, 'recorded_at' => $now]);
            }
        }
        // Containers gone from Docker are removed, except ones still being created.
        Container::allTenants()->where('server_id', $server->id)
            ->where(fn ($q) => $q->whereNotIn('docker_id', $seen ?: [''])->whereNotNull('docker_id'))
            ->delete();
    }

    private function reconcileImages(Server $server, array $reported): void
    {
        $seen = [];
        foreach ($reported as $i) {
            if (empty($i['id'])) {
                continue;
            }
            $seen[] = $i['id'];
            $tags = $i['tags'] ?? [];
            $first = $tags[0] ?? null;
            Image::allTenants()->updateOrCreate(
                ['server_id' => $server->id, 'docker_id' => $i['id']],
                ['organization_id' => $server->organization_id, 'tags' => $tags, 'repository' => $first ? preg_replace('/:[^:\/]+$/', '', $first) : null,
                    'digest' => $i['digest'] ?? $i['id'], 'size_mb' => $i['size_mb'] ?? null, 'docker_created_at' => isset($i['created']) ? $this->time($i['created']) : null],
            );
        }
        Image::allTenants()->where('server_id', $server->id)->whereNotIn('docker_id', $seen ?: [''])->delete();
    }

    private function reconcileNetworks(Server $server, array $reported): void
    {
        $seen = [];
        foreach ($reported as $n) {
            if (empty($n['name'])) {
                continue;
            }
            $seen[] = $n['name'];
            Network::allTenants()->updateOrCreate(
                ['server_id' => $server->id, 'name' => $n['name']],
                array_filter(['organization_id' => $server->organization_id, 'docker_id' => $n['id'] ?? null, 'driver' => $n['driver'] ?? 'bridge',
                    'scope' => $n['scope'] ?? null, 'internal' => (bool) ($n['internal'] ?? false), 'status' => 'active',
                    'subnet' => $n['subnet'] ?? null, 'gateway' => $n['gateway'] ?? null], fn ($v) => $v !== null),
            );
        }
        Network::allTenants()->where('server_id', $server->id)->whereNotIn('name', $seen ?: [''])->where('status', 'active')->delete();
    }

    private function reconcileVolumes(Server $server, array $reported): void
    {
        $seen = [];
        foreach ($reported as $v) {
            if (empty($v['name'])) {
                continue;
            }
            $seen[] = $v['name'];
            Volume::allTenants()->updateOrCreate(
                ['server_id' => $server->id, 'name' => $v['name']],
                ['organization_id' => $server->organization_id, 'driver' => $v['driver'] ?? 'local', 'mountpoint' => $v['mountpoint'] ?? null, 'status' => 'active'],
            );
        }
        Volume::allTenants()->where('server_id', $server->id)->whereNotIn('name', $seen ?: [''])->where('status', 'active')->where('type', 'volume')->delete();
    }

    private function time(mixed $value): ?Carbon
    {
        try {
            return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Issues a client certificate bound to server+org (mTLS is enforced at the proxy). */
    private function signCsr(Agent $agent, string $csrPem): ?string
    {
        try {
            $ca = $this->ca();
            $cert = openssl_csr_sign($csrPem, $ca['cert'], $ca['key'], 90, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
            if (! $cert || ! openssl_x509_export($cert, $pem)) {
                return null;
            }
            AgentCertificate::create([
                'organization_id' => $agent->organization_id, 'agent_id' => $agent->id,
                'fingerprint' => openssl_x509_fingerprint($cert, 'sha256'), 'certificate' => $pem, 'expires_at' => now()->addDays(90),
            ]);

            return $pem;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ca(): array
    {
        $dir = storage_path('app/private/ca');
        if (! is_file("{$dir}/ca.key")) {
            @mkdir($dir, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'Docker Manager Portal Agent CA'], $key, ['digest_alg' => 'sha256']);
            $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
            openssl_pkey_export($key, $keyPem);
            openssl_x509_export($cert, $certPem);
            file_put_contents("{$dir}/ca.key", $keyPem);
            chmod("{$dir}/ca.key", 0600);
            file_put_contents("{$dir}/ca.crt", $certPem);
        }

        return ['cert' => file_get_contents("{$dir}/ca.crt"), 'key' => file_get_contents("{$dir}/ca.key")];
    }
}
