<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\EnrollmentToken;
use App\Models\Job;
use App\Models\Server;
use Illuminate\Support\Str;

class ServerService
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    /** SRV-002: single-use token, valid 15 min, stored as sha256 only. */
    public function createEnrollment(Server $server): array
    {
        EnrollmentToken::where('server_id', $server->id)->whereNull('used_at')->delete();
        $token = Str::random(48);
        $enrollment = EnrollmentToken::create([
            'organization_id' => $server->organization_id,
            'server_id' => $server->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(config('platform.enrollment_ttl_minutes')),
            'created_by' => auth()->id(),
        ]);

        return [
            'server_id' => $server->id,
            'token' => $token,
            'expires_at' => $enrollment->expires_at->toIso8601ZuluString(),
            'install_command' => $this->installCommand($token),
        ];
    }

    public function installCommand(string $token): string
    {
        // Settings override the env default so the panel URL can be changed without a deploy.
        $configured = data_get(\App\Support\Tenant::current()?->settings, 'general.panel_url');
        $panel = rtrim($configured ?: config('platform.panel_url'), '/');

        return "curl -fsSL {$panel}/agent/v1/install.sh | sudo sh -s -- --url {$panel} --token {$token}";
    }

    /** SRV-003 verification checks from enrolment facts and heartbeat. */
    public function verification(Server $server): array
    {
        $supported = ['ubuntu 22.04', 'ubuntu 24.04', 'debian 12', 'rocky 9', 'almalinux 9', 'rocky linux 9'];
        $os = strtolower((string) $server->os);
        $dockerMajor = (int) explode('.', (string) $server->docker_version)[0];
        $fresh = $server->last_heartbeat_at && $server->last_heartbeat_at->gt(now()->subSeconds(config('platform.offline_after_seconds')));
        $checks = [
            ['name' => 'agent', 'ok' => (bool) $server->agent, 'detail' => $server->agent ? 'Agent enrolled ('.($server->agent_version ?? 'unknown').')' : 'Agent not enrolled yet'],
            ['name' => 'heartbeat', 'ok' => $fresh, 'detail' => $server->last_heartbeat_at ? 'Last heartbeat '.$server->last_heartbeat_at->diffForHumans() : 'No heartbeat received'],
            ['name' => 'docker', 'ok' => $dockerMajor >= 24 && ! $server->docker_error, 'detail' => $server->docker_error ?: ($server->docker_version ? "Docker {$server->docker_version} (24+ required)" : 'Docker version unknown')],
            ['name' => 'os', 'ok' => collect($supported)->contains(fn ($s) => str_contains($os, $s)), 'detail' => $server->os ?: 'Unknown OS'],
            ['name' => 'kernel', 'ok' => (bool) $server->kernel, 'detail' => $server->kernel ?: 'Unknown kernel'],
            ['name' => 'cpu', 'ok' => ($server->cpu_cores ?? 0) >= 1, 'detail' => ($server->cpu_cores ?? 0).' cores'],
            ['name' => 'ram', 'ok' => ($server->ram_mb ?? 0) >= 1024, 'detail' => ($server->ram_mb ?? 0).' MB (1024 MB minimum)'],
            ['name' => 'disk', 'ok' => ($server->disk_gb ?? 0) >= 20, 'detail' => ($server->disk_gb ?? 0).' GB (20 GB minimum)'],
            ['name' => 'networking', 'ok' => $fresh, 'detail' => $fresh ? 'Outbound HTTPS to the panel works' : 'Waiting for the agent to connect'],
        ];

        return ['server_id' => $server->id, 'ok' => collect($checks)->every('ok'), 'checks' => $checks];
    }

    public function agentCommand(Server $server, string $action): Job
    {
        if (! $server->agent) {
            throw ApiException::conflict('This server has no enrolled agent.');
        }
        $job = $this->jobs->create("agent.{$action}", ['execute'], $server);
        $payload = $action === 'update' ? $this->updatePayload() : [];
        $this->bus->dispatch($server, "agent.{$action}", $payload, $job);

        return $job;
    }

    /** SRV-006 / SEC-008: signed release metadata; the agent verifies the ed25519 signature. */
    private function updatePayload(): array
    {
        $version = config('platform.agent_latest_version');
        $panel = rtrim(config('platform.panel_url'), '/');
        $manifest = config('platform.agent_binary_dir').'/release.json';
        $meta = is_file($manifest) ? (json_decode(file_get_contents($manifest), true) ?: []) : [];
        $arch = 'amd64';

        return array_filter([
            'url' => $meta['url'][$arch] ?? "{$panel}/agent/v1/download/dockermgr-agent-linux-{$arch}",
            'signature' => $meta['signature'][$arch] ?? '',
            'sha256' => $meta['sha256'][$arch] ?? null,
            'version' => $meta['version'] ?? $version,
        ], fn ($v) => $v !== null);
    }
}
