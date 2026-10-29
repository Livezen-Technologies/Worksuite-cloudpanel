<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Domain;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\UptimeCheck;
use Illuminate\Support\Facades\Http;

/**
 * Website uptime. Every verified domain is fetched on a schedule; a failure is
 * confirmed by a second attempt before an incident opens, so a single dropped
 * request does not page anyone, and the incident closes itself on recovery.
 */
class UptimeService
{
    private const TIMEOUT = 15;
    private const RETRY_DELAY = 3;

    public function __construct(private NotificationService $notifications, private CommandBus $bus) {}

    /** Monitoring settings for one tenant, falling back to the platform defaults. */
    private function settings(?string $orgId): array
    {
        static $cache = [];
        $key = $orgId ?? 'platform';
        if (! array_key_exists($key, $cache)) {
            $org = $orgId ? Organization::find($orgId) : null;
            $cache[$key] = array_replace(
                config('platform.default_settings.monitoring'),
                $org->settings['monitoring'] ?? []
            );
        }

        return $cache[$key];
    }

    /**
     * True while a maintenance window that suppresses alerts covers this
     * resource. A window on a server covers the containers on it, because
     * taking a host down takes its containers with it.
     */
    private function inMaintenance(?string $orgId, string $type, string $id): bool
    {
        $windows = MaintenanceWindow::allTenants()
            ->where('organization_id', $orgId)
            ->where('suppress_alerts', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->get();

        if ($windows->isEmpty()) {
            return false;
        }
        // A window with neither a server nor a group covers the whole tenant.
        foreach ($windows as $w) {
            if (! $w->server_id && ! $w->server_group_id) {
                return true;
            }
        }

        $serverId = match ($type) {
            'server' => $id,
            'container' => \App\Models\Container::allTenants()->whereKey($id)->value('server_id'),
            'domain' => \App\Models\Application::allTenants()
                ->whereKey(Domain::allTenants()->whereKey($id)->value('application_id'))->value('server_id'),
            default => null,
        };
        if (! $serverId) {
            return false;
        }
        $groupId = \App\Models\Server::allTenants()->whereKey($serverId)->value('server_group_id');

        return $windows->contains(fn (MaintenanceWindow $w) => $w->server_id === $serverId
            || ($w->server_group_id && $w->server_group_id === $groupId));
    }

    /**
     * Notification gate. One open alert per resource is the deduplication;
     * this decides whether that alert may notify *again* yet, and whether it
     * is time to escalate. A resolution always notifies, so a recovery is
     * never swallowed by a cooldown.
     */
    private function shouldNotify(Alert $alert, bool $resolving = false): bool
    {
        if ($resolving) {
            return $alert->notify_count > 0;   // only if the opening was announced
        }
        if ($alert->suppressed) {
            return false;
        }
        $cooldown = (int) $this->settings($alert->organization_id)['notify_cooldown_minutes'];

        return ! $alert->last_notified_at || $alert->last_notified_at->lt(now()->subMinutes($cooldown));
    }

    private function markNotified(Alert $alert): void
    {
        $alert->last_notified_at = now();
        $alert->notify_count = (int) $alert->notify_count + 1;
        $alert->saveQuietly();
    }

    /**
     * Re-notifies open incidents whose cooldown has elapsed, and escalates
     * those that have been open too long. Escalation raises the severity to
     * emergency once, so it reaches whoever watches that tier.
     */
    public function followUpOpenIncidents(): array
    {
        $renotified = $escalated = 0;

        foreach (Alert::allTenants()->where('status', 'open')->get() as $alert) {
            if ($this->inMaintenance($alert->organization_id, (string) $alert->resource_type, (string) $alert->resource_id)) {
                continue;
            }
            $s = $this->settings($alert->organization_id);
            $after = (int) $s['escalate_after_minutes'];

            if ($after > 0 && ! $alert->escalated_at && $alert->created_at?->lt(now()->subMinutes($after))) {
                $alert->escalated_at = now();
                $alert->severity = 'emergency';
                $alert->saveQuietly();
                $open = $alert->created_at->diffForHumans(now(), true);
                $this->notifyOrg($alert->organization_id, "ESCALATED: {$alert->title}", "Still open after {$open}. {$alert->message}");
                $this->markNotified($alert);
                $escalated++;

                continue;
            }

            if ($this->shouldNotify($alert)) {
                $open = $alert->created_at?->diffForHumans(now(), true) ?? 'a while';
                $this->notifyOrg($alert->organization_id, "Still down: {$alert->title}", "Open for {$open}. {$alert->message}");
                $this->markNotified($alert);
                $renotified++;
            }
        }

        return ['renotified' => $renotified, 'escalated' => $escalated];
    }

    /**
     * Restarts containers that are running but unhealthy. Docker's own
     * restart policy (unless-stopped) already covers a container that exits;
     * it does nothing for one that stays up and fails its health check, which
     * is the case this handles. Off by default, and rate-limited so a
     * genuinely broken container is not restarted in a loop.
     *
     * @return array{restarted:int,skipped:int}
     */
    public function autoRestartUnhealthy(): array
    {
        $restarted = $skipped = 0;

        foreach (\App\Models\Container::allTenants()->where('health', 'unhealthy')->where('state', 'running')->get() as $c) {
            $s = $this->settings($c->organization_id);
            if (! $s['auto_restart_unhealthy']) {
                continue;
            }
            if ($this->inMaintenance($c->organization_id, 'container', $c->id)) {
                continue;
            }
            // Count only attempts inside the last hour.
            $recent = $c->last_auto_restart_at && $c->last_auto_restart_at->gt(now()->subHour())
                ? (int) $c->auto_restart_attempts
                : 0;
            if ($recent >= (int) $s['auto_restart_max_per_hour']) {
                $skipped++;

                continue;
            }
            $server = $c->server;
            if (! $server) {
                continue;
            }
            // Same payload shape ScheduledJobService uses for container_restart.
            $this->bus->dispatch($server, 'container.restart', ['container_id' => $c->dockerRef()]);
            $c->auto_restart_attempts = $recent + 1;
            $c->last_auto_restart_at = now();
            $c->saveQuietly();
            $this->notifyOrg($c->organization_id, "Restarting {$c->name}",
                "The container was unhealthy and has been restarted automatically (attempt {$c->auto_restart_attempts} this hour).");
            $restarted++;
        }

        return ['restarted' => $restarted, 'skipped' => $skipped];
    }

    /** Uptime percentage and downtime history for one domain over a window. */
    public function report(Domain $domain, string $range = '30d'): array
    {
        $since = match ($range) {
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            default => now()->subDays(30),
        };
        $checks = UptimeCheck::allTenants()->where('domain_id', $domain->id)
            ->where('checked_at', '>=', $since)->orderBy('checked_at')->get();

        $total = $checks->count();
        $up = $checks->where('ok', true)->count();
        $latencies = $checks->where('ok', true)->pluck('ms')->filter()->sort()->values();

        return [
            'domain' => $domain->name,
            'domain_id' => $domain->id,
            'range' => $range,
            // null, not 100: no checks means unknown, and claiming 100% would
            // be a guess dressed as a measurement.
            'uptime_pct' => $total ? round($up / $total * 100, 3) : null,
            'checks' => $total,
            'failed' => $total - $up,
            'avg_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
            'p95_ms' => $latencies->isEmpty() ? null : (int) $latencies[(int) floor($latencies->count() * 0.95) - ($latencies->count() > 1 ? 1 : 0)],
            'incidents' => Alert::allTenants()->where('resource_type', 'domain')->where('resource_id', $domain->id)
                ->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(20)->get()
                ->map(fn (Alert $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'status' => $a->status,
                    'severity' => $a->severity,
                    'started_at' => $a->created_at?->toIso8601ZuluString(),
                    'resolved_at' => $a->resolved_at?->toIso8601ZuluString(),
                    'minutes' => $a->resolved_at
                        ? (int) $a->created_at->diffInMinutes($a->resolved_at)
                        : (int) $a->created_at->diffInMinutes(now()),
                ])->all(),
        ];
    }

    /** @return array{checked:int,down:int,recovered:int} */
    public function checkAll(): array
    {
        $checked = $down = $recovered = 0;

        foreach (Domain::allTenants()->where('dns_status', 'verified')->get() as $domain) {
            $checked++;
            $result = $this->probe($domain->name);

            if (! $result['ok']) {
                // Confirm before opening: transient failures are common.
                sleep(self::RETRY_DELAY);
                $result = $this->probe($domain->name);
            }

            // Every probe is recorded, so uptime is measured rather than
            // inferred from incident rows.
            UptimeCheck::create([
                'organization_id' => $domain->organization_id,
                'domain_id' => $domain->id,
                'ok' => $result['ok'],
                'status' => $result['status'],
                'ms' => $result['ms'],
                'error' => $result['error'],
                'checked_at' => now(),
            ]);

            $open = $this->openIncident($domain);

            if ($result['ok']) {
                if ($open) {
                    $this->close($domain, $open, $result);
                    $recovered++;
                }

                continue;
            }

            $down++;
            if (! $open) {
                $this->open($domain, $result);
            }
        }

        return ['checked' => $checked, 'down' => $down, 'recovered' => $recovered];
    }

    /** @return array{ok:bool,status:int|null,ms:int,error:string|null} */
    private function probe(string $host): array
    {
        $started = microtime(true);
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withoutVerifying()      // the certificate has its own check
                ->withHeaders(['User-Agent' => 'WorkSuite-Monitor/1.0'])
                ->get("https://{$host}/");
            $ms = (int) ((microtime(true) - $started) * 1000);

            return [
                'ok' => $response->status() < 500,
                'status' => $response->status(),
                'ms' => $ms,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'ms' => (int) ((microtime(true) - $started) * 1000),
                'error' => class_basename($e).': '.str($e->getMessage())->limit(160),
            ];
        }
    }

    private function openIncident(Domain $domain): ?Alert
    {
        return Alert::allTenants()
            ->where('resource_type', 'domain')
            ->where('resource_id', $domain->id)
            ->where('status', 'open')
            ->first();
    }

    private function open(Domain $domain, array $result): void
    {
        $reason = $result['error'] ?? "HTTP {$result['status']}";

        $alert = new Alert();
        $alert->organization_id = $domain->organization_id;
        $alert->resource_type = 'domain';
        $alert->resource_id = $domain->id;
        $alert->severity = 'critical';
        $alert->status = 'open';
        $alert->title = "{$domain->name} is down";
        $alert->message = "Two consecutive checks failed: {$reason}.";
        $alert->value = $result['status'];
        $alert->suppressed = $this->inMaintenance($domain->organization_id, 'domain', $domain->id);
        $alert->save();

        // The incident is always recorded; a maintenance window only silences it.
        if ($this->shouldNotify($alert)) {
            $this->notify($domain, $alert->title, $alert->message);
            $this->markNotified($alert);
        }
    }

    private function close(Domain $domain, Alert $alert, array $result): void
    {
        $alert->status = 'resolved';
        $alert->resolved_at = now();
        $alert->save();

        $downFor = $alert->created_at?->diffForHumans(now(), true) ?? 'an unknown period';
        if ($this->shouldNotify($alert, resolving: true)) {
            $this->notify(
                $domain,
                "{$domain->name} is back up",
                "Recovered after {$downFor}. Responded HTTP {$result['status']} in {$result['ms']} ms."
            );
        }
    }

    /** Notifies the owning tenant's channels; silence here must never stop the sweep. */
    private function notify(Domain $domain, string $title, string $message): void
    {
        $channels = NotificationChannel::allTenants()
            ->where('organization_id', $domain->organization_id)
            ->where('enabled', true)
            ->get();

        foreach ($channels as $channel) {
            try {
                $this->notifications->send($channel, $title, $message, ['domain' => $domain->name]);
            } catch (\Throwable) {
                // keep checking the rest
            }
        }
    }

    /** Containers reported unhealthy or exited, and servers whose agent went quiet. */
    public function checkInfrastructure(): array
    {
        $opened = $closed = 0;

        foreach (\App\Models\Container::allTenants()->get() as $c) {
            $bad = $c->health === 'unhealthy' || in_array($c->state, ['exited', 'dead'], true);
            // Infrastructure the panel depends on is reported, never acted on.
            $open = $this->incidentFor('container', $c->id);
            if ($bad && ! $open) {
                $this->raise($c->organization_id, 'container', $c->id, 'critical',
                    "{$c->name} is {$c->state}" . ($c->health === 'unhealthy' ? ' and unhealthy' : ''),
                    "The container stopped serving. Image {$c->image}.");
                $opened++;
            } elseif (! $bad && $open) {
                $this->resolve($open, "{$c->name} recovered", "State {$c->state}, health {$c->health}.");
                $closed++;
            }
        }

        foreach (\App\Models\Server::allTenants()->get() as $srv) {
            $last = $srv->last_heartbeat_at ? strtotime((string) $srv->last_heartbeat_at) : 0;
            $stale = ! $last || (time() - $last) > 600;   // ten minutes of silence
            $open = $this->incidentFor('server', $srv->id);
            if ($stale && ! $open) {
                $ago = $last ? round((time() - $last) / 60) . ' minutes ago' : 'never';
                $this->raise($srv->organization_id, 'server', $srv->id, 'critical',
                    "{$srv->name} stopped reporting", "Last heartbeat {$ago}.");
                $opened++;
            } elseif (! $stale && $open) {
                $this->resolve($open, "{$srv->name} is reporting again", 'The agent resumed heartbeats.');
                $closed++;
            }
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    private function incidentFor(string $type, string $id): ?Alert
    {
        return Alert::allTenants()->where('resource_type', $type)->where('resource_id', $id)
            ->where('status', 'open')->first();
    }

    private function raise(?string $orgId, string $type, string $id, string $severity, string $title, string $message): void
    {
        $a = new Alert();
        $a->organization_id = $orgId;
        $a->resource_type = $type;
        $a->resource_id = $id;
        $a->severity = $severity;
        $a->status = 'open';
        $a->title = $title;
        $a->message = $message;
        $a->suppressed = $this->inMaintenance($orgId, $type, $id);
        $a->save();
        if ($this->shouldNotify($a)) {
            $this->notifyOrg($orgId, $title, $message);
            $this->markNotified($a);
        }
    }

    private function resolve(Alert $a, string $title, string $message): void
    {
        $wasAnnounced = $this->shouldNotify($a, resolving: true);
        $a->status = 'resolved';
        $a->resolved_at = now();
        $a->save();
        if ($wasAnnounced) {
            $this->notifyOrg($a->organization_id, $title, $message);
        }
    }

    private function notifyOrg(?string $orgId, string $title, string $message): void
    {
        foreach (NotificationChannel::allTenants()->where('organization_id', $orgId)->where('enabled', true)->get() as $ch) {
            try {
                $this->notifications->send($ch, $title, $message);
            } catch (\Throwable) {
            }
        }
    }
}
