<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Domain;
use App\Support\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled maintenance windows on an app (maintenance_scheduled_start / _end / maintenance_notice).
 *
 * At start the scheduler locks the app the same way the maintenance toggle does (public_access off,
 * reason = the notice, maintenance_until = end) and marks it maintenance_auto_locked; at end it
 * unlocks it only if it locked it itself, then clears the window. Both steps are idempotent and audited.
 */
class MaintenanceScheduleService
{
    public function __construct(private AuditLogger $audit, private TraefikConfigService $traefik) {}

    /** Validation rules for a {start, end, message} payload (under $prefix). */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'start' => 'required|date',
            $prefix.'end' => 'required|date|after:'.$prefix.'start|after:now',
            $prefix.'message' => 'nullable|string|max:500',
        ];
    }

    /** Sets (array) or clears (null) the window. Returns the app, fresh. */
    public function set(Application $app, ?array $window, ?string $organizationId = null): Application
    {
        $before = $this->present($app);
        if ($window === null) {
            $app->forceFill(['maintenance_scheduled_start' => null, 'maintenance_scheduled_end' => null, 'maintenance_notice' => null])->save();
            $this->audit->log('app.maintenance.schedule.clear', 'applications', $app->id, ['application' => $app->name, 'previous' => $before], $organizationId ?? $app->organization_id);
        } else {
            $start = Carbon::parse($window['start'])->utc();
            $end = Carbon::parse($window['end'])->utc();
            if ($end->lessThanOrEqualTo($start)) {
                throw ApiException::validation(['maintenance_schedule.end' => ['The end must be after the start.']]);
            }
            $message = trim((string) ($window['message'] ?? '')) ?: null;
            $app->forceFill(['maintenance_scheduled_start' => $start, 'maintenance_scheduled_end' => $end, 'maintenance_notice' => $message])->save();
            $this->audit->log('app.maintenance.schedule', 'applications', $app->id, ['application' => $app->name, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'message' => $message, 'previous' => $before], $organizationId ?? $app->organization_id);
        }
        $this->forget($app);
        // A window that is already open locks now, not at the next scheduler tick.
        $this->apply($app->fresh());

        return $app->fresh();
    }

    /** Called on a manual unlock: a window that has started ends with it, so the scheduler does not re-lock. */
    public static function endedByUnlock(Application $app): array
    {
        $started = $app->maintenance_scheduled_start && $app->maintenance_scheduled_start->lessThanOrEqualTo(now());

        return ['maintenance_auto_locked' => false] + ($started ? ['maintenance_scheduled_start' => null, 'maintenance_scheduled_end' => null, 'maintenance_notice' => null] : []);
    }

    /** One scheduler pass over every app with a window. Returns [locked, unlocked]. */
    public function run(): array
    {
        $counts = [0, 0];
        Application::allTenants()->where(fn ($q) => $q->whereNotNull('maintenance_scheduled_start')->orWhere('maintenance_auto_locked', true))
            ->get()->each(function (Application $app) use (&$counts) {
                $r = $this->apply($app);
                $r === 'locked' && $counts[0]++;
                $r === 'unlocked' && $counts[1]++;
            });

        return $counts;
    }

    /** Applies one app's window as of now: 'locked', 'unlocked', or null when nothing changed. */
    public function apply(Application $app): ?string
    {
        $start = $app->maintenance_scheduled_start;
        $end = $app->maintenance_scheduled_end;
        $now = now();

        if ($start && $end && $start->lessThanOrEqualTo($now) && $end->greaterThan($now)) {
            if ($app->public_access === false) {
                return null; // already locked (by us earlier, or by hand: then it is not ours to unlock)
            }
            $app->forceFill(['public_access' => false, 'lock_reason' => $app->maintenance_notice ? mb_substr($app->maintenance_notice, 0, 255) : 'Scheduled maintenance',
                'locked_at' => $now, 'locked_by' => null, 'maintenance_until' => $end, 'maintenance_auto_locked' => true])->save();
            $this->audit->log('app.maintenance.auto_lock', 'applications', $app->id, ['application' => $app->name, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String()], $app->organization_id, null, 'scheduler');
            $this->forget($app);
            $this->traefik->push();

            return 'locked';
        }

        if (($end && $end->lessThanOrEqualTo($now)) || (! $start && $app->maintenance_auto_locked)) {
            $ours = $app->maintenance_auto_locked && $app->public_access === false;
            $app->forceFill(['maintenance_scheduled_start' => null, 'maintenance_scheduled_end' => null, 'maintenance_notice' => null, 'maintenance_auto_locked' => false]
                + ($ours ? ['public_access' => true, 'lock_reason' => null, 'locked_at' => null, 'locked_by' => null, 'maintenance_until' => null] : []))->save();
            $this->audit->log($ours ? 'app.maintenance.auto_unlock' : 'app.maintenance.schedule.expired', 'applications', $app->id, ['application' => $app->name, 'end' => $end?->toIso8601String()], $app->organization_id, null, 'scheduler');
            $this->forget($app);
            $ours && $this->traefik->push();

            return $ours ? 'unlocked' : null;
        }

        return null;
    }

    /** The window as the APIs return it. */
    public function present(Application $app): ?array
    {
        return $app->maintenance_scheduled_start ? [
            'start' => $app->maintenance_scheduled_start->toIso8601String(),
            'end' => $app->maintenance_scheduled_end?->toIso8601String(),
            'message' => $app->maintenance_notice,
            'auto_locked' => (bool) $app->maintenance_auto_locked,
        ] : null;
    }

    /** The public notice: {scheduled, active}. */
    public function notice(?Application $app): array
    {
        return [
            'scheduled' => $app && $app->maintenance_scheduled_start ? [
                'start' => $app->maintenance_scheduled_start->toIso8601String(),
                'end' => $app->maintenance_scheduled_end?->toIso8601String(),
                'message' => $app->maintenance_notice,
            ] : null,
            'active' => $app ? $app->public_access === false : false,
        ];
    }

    /** Origins allowed to read the notice from a browser: the app's own verified domains, over https. */
    public static function origins(Application $app): array
    {
        return Domain::allTenants()->where('application_id', $app->id)->where('dns_status', 'verified')->pluck('name')
            ->map(fn ($d) => 'https://'.strtolower($d))->values()->all();
    }

    public function forget(Application $app): void
    {
        Cache::forget('maintenance-notice:'.$app->id);
        Cache::forget('maintenance-page:'.$app->id);
    }
}
