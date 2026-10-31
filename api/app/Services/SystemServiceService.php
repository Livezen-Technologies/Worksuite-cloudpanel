<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Alert;
use App\Models\Job;
use App\Models\NotificationChannel;
use App\Models\Server;

/**
 * System services (SVC-1..5): the systemd services on each server, their
 * health, start/stop/enable, set-up from a fixed catalogue, and custom
 * services the panel creates (ws-*.service). Watched services are checked
 * every five minutes and raise an alert while they are down.
 */
class SystemServiceService implements HandlesCommandResults
{
    /** key => [label, unit, what it is]. The agent holds the package list. */
    public const CATALOG = [
        'nginx' => ['Nginx', 'nginx.service', 'Web server and reverse proxy'],
        'apache' => ['Apache', 'apache2.service', 'Web server'],
        'mysql' => ['MySQL', 'mysql.service', 'Relational database'],
        'mariadb' => ['MariaDB', 'mariadb.service', 'MySQL-compatible database'],
        'postgresql' => ['PostgreSQL', 'postgresql.service', 'Relational database'],
        'redis' => ['Redis', 'redis-server.service', 'In-memory cache and queue'],
        'memcached' => ['Memcached', 'memcached.service', 'In-memory cache'],
        'php-fpm' => ['PHP-FPM', 'php-fpm.service', 'PHP process manager'],
        'supervisor' => ['Supervisor', 'supervisor.service', 'Keeps workers running'],
        'fail2ban' => ['Fail2ban', 'fail2ban.service', 'Bans IPs after failed logins'],
        'chrony' => ['Chrony', 'chrony.service', 'Keeps the clock in sync'],
        'cron' => ['Cron', 'cron.service', 'Runs scheduled tasks'],
    ];

    public const ACTIONS = ['start', 'stop', 'restart', 'reload', 'enable', 'disable'];

    /** Never stopped or disabled from the panel: the server would drop off it. */
    public const PROTECTED = ['dockermgr-agent.service', 'ssh.service', 'sshd.service', 'docker.service', 'containerd.service', 'systemd-journald.service', 'dbus.service', 'systemd-networkd.service', 'systemd-resolved.service'];

    public const UNIT = '/^[a-zA-Z0-9@._-]{1,120}\.service$/';

    public function __construct(private CommandBus $bus, private JobService $jobs, private NotificationService $notifications) {}

    public function present(Server $server): array
    {
        $rows = collect($server->system_services ?? [])->map(fn ($r) => $r + ['watched' => in_array($r['unit'] ?? '', $server->services_watch ?? [], true)])->values();

        return [
            'server' => ['id' => $server->id, 'name' => $server->name, 'online' => $server->isOnline()],
            'checked_at' => $server->system_services_at?->toIso8601ZuluString(),
            'services' => $rows->all(),
            'watch' => array_values($server->services_watch ?? []),
            'summary' => [
                'total' => $rows->count(),
                'running' => $rows->where('active', 'active')->count(),
                'failed' => $rows->where('active', 'failed')->count(),
                'custom' => $rows->where('custom', true)->count(),
                'watched_down' => $rows->filter(fn ($r) => $r['watched'] && ($r['active'] ?? '') !== 'active')->count(),
            ],
            'catalog' => collect(self::CATALOG)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'unit' => $c[1], 'description' => $c[2],
                'installed' => $rows->contains(fn ($r) => ($r['unit'] ?? '') === $c[1] || ($k === 'php-fpm' && preg_match('/^php[0-9.]*-fpm\.service$/', $r['unit'] ?? '')))])->values()->all(),
            'pending' => Job::where('type', 'system.services')->where('resource_id', $server->id)->whereIn('status', ['queued', 'running'])->count(),
        ];
    }

    private function send(Server $server, string $type, array $payload, ?string $userId): Job
    {
        if (! $server->isOnline()) {
            throw ApiException::conflict("Server {$server->name} is offline.");
        }
        $job = $this->jobs->create('system.services', ['execute'], $server, ['command' => $type] + $payload, $userId, $server->organization_id);
        $this->bus->dispatch($server, $type, $payload, $job);

        return $job;
    }

    public function refresh(Server $server, ?string $userId = null): Job
    {
        return $this->send($server, 'svc.list', [], $userId);
    }

    public function action(Server $server, string $unit, string $action, ?string $userId): Job
    {
        $this->checkUnit($unit);
        if (! in_array($action, self::ACTIONS, true)) {
            throw ApiException::validation(['action' => ['Unknown action.']]);
        }
        if (in_array($unit, self::PROTECTED, true) && in_array($action, ['stop', 'disable'], true)) {
            throw ApiException::conflict("{$unit} keeps this server connected to the panel and cannot be {$action}" .($action === 'stop' ? 'ped' : 'd').' here.');
        }

        return $this->send($server, 'svc.action', ['unit' => $unit, 'action' => $action], $userId);
    }

    public function install(Server $server, string $key, ?string $userId): Job
    {
        if (! isset(self::CATALOG[$key])) {
            throw ApiException::validation(['key' => ['Unknown service.']]);
        }

        return $this->send($server, 'svc.install', ['key' => $key], $userId);
    }

    public function create(Server $server, array $data, ?string $userId): Job
    {
        return $this->send($server, 'svc.create', $data, $userId);
    }

    public function delete(Server $server, string $unit, ?string $userId): Job
    {
        if (! preg_match('/^ws-[a-z0-9][a-z0-9-]{0,40}\.service$/', $unit)) {
            throw ApiException::validation(['unit' => ['Only services created in the panel can be deleted.']]);
        }

        return $this->send($server, 'svc.delete', ['unit' => $unit], $userId);
    }

    public function logs(Server $server, string $unit, int $lines, ?string $userId): Job
    {
        $this->checkUnit($unit);

        return $this->send($server, 'svc.logs', ['unit' => $unit, 'lines' => $lines], $userId);
    }

    public function watch(Server $server, array $units): Server
    {
        foreach ($units as $u) {
            $this->checkUnit((string) $u);
        }
        $server->update(['services_watch' => array_values(array_unique($units))]);
        $this->evaluate($server->fresh());

        return $server->fresh();
    }

    private function checkUnit(string $unit): void
    {
        if (! preg_match(self::UNIT, $unit)) {
            throw ApiException::validation(['unit' => ['Not a valid service name (e.g. nginx.service).']]);
        }
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $server = Server::allTenants()->find($command->server_id);
        $out = (array) ($command->output ?? []);
        if ($command->status !== 'succeeded' || ! $server) {
            $this->jobs->fail($job, $command->message ?: 'The agent could not run the command.', 'execute');

            return;
        }
        if ($command->type === 'svc.list') {
            $server->forceFill(['system_services' => array_values((array) ($out['services'] ?? [])), 'system_services_at' => now()])->save();
            $this->evaluate($server);
        } elseif (in_array($command->type, ['svc.action', 'svc.install', 'svc.create'], true) && isset($out['unit'])) {
            $rows = collect($server->system_services ?? [])->reject(fn ($r) => ($r['unit'] ?? '') === $out['unit'])->push($out)->sortBy('unit')->values()->all();
            $server->forceFill(['system_services' => $rows])->save();
            $this->evaluate($server);
        } elseif ($command->type === 'svc.delete') {
            $unit = $command->payload['unit'] ?? '';
            $server->forceFill([
                'system_services' => collect($server->system_services ?? [])->reject(fn ($r) => ($r['unit'] ?? '') === $unit)->values()->all(),
                'services_watch' => array_values(array_diff($server->services_watch ?? [], [$unit])),
            ])->save();
        }
        $this->jobs->succeed($job, $command->type === 'svc.list' ? ['count' => count((array) ($out['services'] ?? []))] : $out);
    }

    /** Watched services that are not running get one open alert each; it resolves when they run again. */
    public function evaluate(Server $server): void
    {
        $rows = collect($server->system_services ?? [])->keyBy('unit');
        foreach ($server->services_watch ?? [] as $unit) {
            $row = $rows->get($unit);
            $title = "Service {$unit} is down on {$server->name}";
            $open = Alert::allTenants()->where('resource_type', 'server')->where('resource_id', $server->id)->where('title', $title)->whereIn('status', ['open', 'acknowledged']);
            if ($row && ($row['active'] ?? '') === 'active') {
                $open->update(['status' => 'resolved', 'resolved_at' => now()]);

                continue;
            }
            if (! $server->system_services_at || $open->exists()) {
                continue;
            }
            $state = $row ? ($row['active'] ?? 'unknown').($row['sub'] ?? '' ? ' ('.$row['sub'].')' : '') : 'not installed';
            $message = "{$unit} is {$state}. Open Resources → System services to restart it or read its log.";
            $alert = Alert::create(['organization_id' => $server->organization_id, 'resource_type' => 'server', 'resource_id' => $server->id, 'severity' => 'critical', 'title' => $title, 'message' => $message]);
            foreach (NotificationChannel::allTenants()->where('organization_id', $server->organization_id)->where('enabled', true)->get() as $channel) {
                $this->notifications->send($channel, "[CRITICAL] {$title}", $message, ['alert_id' => $alert->id]);
            }
        }
        // A service no longer watched leaves no alert behind.
        Alert::allTenants()->where('resource_type', 'server')->where('resource_id', $server->id)->whereIn('status', ['open', 'acknowledged'])
            ->where('title', 'like', 'Service % is down on %')->get()
            ->reject(fn ($a) => collect($server->services_watch ?? [])->contains(fn ($u) => $a->title === "Service {$u} is down on {$server->name}"))
            ->each->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    /** Scheduler: a fresh snapshot from every online server that watches services. */
    public function checkAll(): int
    {
        $n = 0;
        foreach (Server::allTenants()->whereNotNull('services_watch')->get() as $server) {
            if (! $server->services_watch || ! $server->isOnline() || $server->maintenance) {
                continue;
            }
            $busy = Job::allTenants()->where('type', 'system.services')->where('resource_id', $server->id)->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subMinutes(10))->exists();
            if (! $busy) {
                app(\App\Support\Tenant::class)->as($server->organization_id, fn () => $this->refresh($server));
                $n++;
            }
        }

        return $n;
    }
}
