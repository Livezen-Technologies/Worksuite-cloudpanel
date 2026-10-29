<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Domain;
use App\Models\NotificationChannel;
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

    public function __construct(private NotificationService $notifications) {}

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
        $alert->save();

        $this->notify($domain, $alert->title, $alert->message);
    }

    private function close(Domain $domain, Alert $alert, array $result): void
    {
        $alert->status = 'resolved';
        $alert->resolved_at = now();
        $alert->save();

        $downFor = $alert->created_at?->diffForHumans(now(), true) ?? 'an unknown period';
        $this->notify(
            $domain,
            "{$domain->name} is back up",
            "Recovered after {$downFor}. Responded HTTP {$result['status']} in {$result['ms']} ms."
        );
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
        $a->save();
        $this->notifyOrg($orgId, $title, $message);
    }

    private function resolve(Alert $a, string $title, string $message): void
    {
        $a->status = 'resolved';
        $a->resolved_at = now();
        $a->save();
        $this->notifyOrg($a->organization_id, $title, $message);
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
