<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\BackupJob;
use App\Models\Certificate;
use App\Models\Container;
use App\Models\Domain;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Support\Tenant;

/** MON-003/004: evaluates alert rules per organisation, opens/resolves alerts, notifies channels. */
class AlertService
{
    public function __construct(private MetricsService $metrics, private NotificationService $notifications) {}

    public function seedDefaults(Organization $org): void
    {
        foreach (config('platform.default_alert_rules') as $rule) {
            AlertRule::allTenants()->firstOrCreate(['organization_id' => $org->id, 'metric' => $rule['metric'], 'is_default' => true], $rule + ['enabled' => true]);
        }
    }

    public function evaluateAll(): array
    {
        $stats = ['opened' => 0, 'resolved' => 0];
        foreach (Organization::all() as $org) {
            app(Tenant::class)->as($org->id, function () use (&$stats) {
                foreach (AlertRule::where('enabled', true)->get() as $rule) {
                    foreach ($this->evaluate($rule) as $key => [$firing, $title, $value, $type, $id]) {
                        $firing ? $stats['opened'] += (int) $this->open($rule, $type, $id, $title, $value) : $stats['resolved'] += $this->resolve($rule, $type, $id);
                    }
                }
            });
        }

        return $stats;
    }

    /** @return array<string, array{0: bool, 1: string, 2: ?float, 3: string, 4: string}> */
    public function evaluate(AlertRule $rule): array
    {
        $results = [];
        $servers = Server::query()->when($rule->scope_type === 'server', fn ($q) => $q->whereKey($rule->scope_id))
            ->when($rule->scope_type === 'server_group', fn ($q) => $q->where('server_group_id', $rule->scope_id))->get()
            ->reject(fn ($s) => $this->inMaintenance($s));
        $cmp = fn (?float $v) => $v !== null && match ($rule->operator) { '<' => $v < $rule->threshold, '>=' => $v >= $rule->threshold, '<=' => $v <= $rule->threshold, default => $v > $rule->threshold };

        switch ($rule->metric) {
            case 'cpu': case 'ram': case 'disk':
                foreach ($servers->where('status', '!=', 'pending') as $s) {
                    $value = $this->metrics->average($s->id, $rule->metric.'_pct', $rule->duration_minutes ?: 1);
                    $results[$s->id] = [$cmp($value), strtoupper($rule->metric)." {$rule->operator} {$rule->threshold}% on {$s->name}", $value, 'Server', $s->id];
                }
                break;
            case 'heartbeat':
                foreach ($servers->where('status', '!=', 'pending') as $s) {
                    $age = $s->last_heartbeat_at ? (float) $s->last_heartbeat_at->diffInSeconds(now()) : null;
                    $results[$s->id] = [$s->status === 'offline', "Agent on {$s->name} stopped reporting", $age, 'Server', $s->id];
                }
                break;
            case 'container_unhealthy':
                foreach (Container::whereIn('server_id', $servers->pluck('id'))->get() as $c) {
                    $results[$c->id] = [$c->health === 'unhealthy', "Container {$c->name} is unhealthy", null, 'Container', $c->id];
                }
                break;
            case 'backup_failed':
                foreach (BackupJob::where('created_at', '>=', now()->subDay())->get()->groupBy(fn ($j) => $j->backup_policy_id ?? $j->target_id) as $jobs) {
                    $last = $jobs->sortBy('created_at')->last();
                    $results[$last->id] = [$last->status === 'failed', 'Backup of '.$last->target_type.' failed', null, 'BackupJob', $last->id];
                }
                break;
            case 'ssl_expiry':
                foreach (Domain::with('certificate')->get() as $d) {
                    $days = $d->certificate?->expires_at ? (float) now()->diffInDays($d->certificate->expires_at, false) : null;
                    if ($days !== null) {
                        $results[$d->id] = [$cmp($days), "Certificate for {$d->name} expires in ".(int) $days.' days', $days, 'Domain', $d->id];
                    }
                }
                break;
        }

        return $results;
    }

    private function inMaintenance(Server $server): bool
    {
        return $server->maintenance || MaintenanceWindow::where('suppress_alerts', true)->where('starts_at', '<=', now())->where('ends_at', '>=', now())
            ->where(fn ($q) => $q->where('server_id', $server->id)->orWhere(fn ($q) => $q->whereNotNull('server_group_id')->where('server_group_id', $server->server_group_id)))->exists();
    }

    private function open(AlertRule $rule, string $type, string $id, string $title, ?float $value): bool
    {
        $existing = Alert::where('alert_rule_id', $rule->id)->where('resource_id', $id)->whereIn('status', ['open', 'acknowledged'])->first();
        if ($existing) {
            $existing->update(['value' => $value]);

            return false;
        }
        $alert = Alert::create(['alert_rule_id' => $rule->id, 'resource_type' => $type, 'resource_id' => $id, 'severity' => $rule->severity, 'title' => $title, 'message' => $rule->name, 'value' => $value]);
        $channels = $rule->channel_ids ? NotificationChannel::whereIn('id', $rule->channel_ids)->where('enabled', true)->get() : NotificationChannel::where('enabled', true)->get();
        foreach ($channels as $channel) {
            $this->notifications->send($channel, '['.strtoupper($alert->severity)."] {$title}", $rule->name, ['alert_id' => $alert->id]);
        }

        return true;
    }

    private function resolve(AlertRule $rule, string $type, string $id): int
    {
        return Alert::where('alert_rule_id', $rule->id)->where('resource_id', $id)->whereIn('status', ['open', 'acknowledged'])->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
