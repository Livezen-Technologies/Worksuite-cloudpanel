<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\BackupJob;
use App\Models\Deployment;
use App\Models\EnterpriseDeployment;
use App\Models\EnterpriseUpdate;
use App\Models\Project;
use App\Models\RestoreJob;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Updates of an enterprise deployment to a newer worksuite-server tag.
 *
 * "A new version is released" means a newer tag is loaded on the enterprise nodes (there is no registry;
 * EnterpriseProvisioningService::versions()). A run is one state machine, advanced by `enterprise:updates`
 * every minute (and once inline when it is started now):
 *
 *   scheduled → backing_up (project backup: database dump + data volumes, ProjectBackupService)
 *             → deploying  (EnterpriseProvisioningService::act redeploy on the new tag; the panel's
 *                           deployment health gate decides)
 *             → succeeded, or rolling_back (redeploy on the previous tag)
 *             → restoring_data (the run's pre-update backup, when restore_data_on_rollback is on; a fresh
 *                               safety backup of the current state is taken first) → rolled_back | failed.
 *
 * A rolled-back run carries data_restore {state: running|succeeded|failed|skipped, ...}: "rolled back, data
 * restore failed" is status rolled_back with data_restore.state failed, and the safety backups are kept.
 *
 * Nothing is changed when the backup fails. The panel owns the scheduler (not Cloud Admin) because every
 * transition waits on agent results that only the panel sees; Cloud Admin reads the state and is told of
 * each transition by the signed `deployment.update` webhook.
 */
class EnterpriseUpdateService
{
    public const MODES = ['off', 'notify', 'auto'];

    public const DEFAULTS = [
        'mode' => 'off', 'channel' => 'latest',
        'window' => ['days' => [7], 'start' => '02:00', 'duration_minutes' => 120, 'timezone' => 'UTC'],
        'notified_version' => null,
        // Hours before the window opens that Cloud emails the customer (0 = no email, and no gate). Cloud owns the mail.
        'notice_hours' => 24,
        // The last deployment.update_scheduled sent: {version, window_start, at, notice_hours, notify_at}. Internal.
        'announced' => null,
        // After an automatic rollback, also restore the run's pre-update backup (DB + files). Owner decision 2026-11-22.
        'restore_data_on_rollback' => true,
    ];

    public const NOTICE_HOURS = [0, 6, 24, 48, 72];

    /** Settings keys the scheduler keeps for itself; never shown or accepted. */
    private const INTERNAL = ['notified_version' => 1, 'announced' => 1];

    public function __construct(
        private EnterpriseProvisioningService $deployments,
        private ProjectBackupService $backups,
        private Tenant $tenant,
        private AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ versions

    /** "3.1.0-4515" → [3,1,0,4515]; tags that are not a version (latest, dev) give null and are never offered. */
    public static function parse(?string $v): ?array
    {
        if (! $v || ! preg_match('/^v?(\d+)\.(\d+)(?:\.(\d+))?(?:[-+](\d+))?$/', $v, $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)];
    }

    public static function newer(string $a, ?string $than): bool
    {
        $pa = self::parse($a);
        $pb = self::parse($than);

        return $pa !== null && ($pb === null ? true : $pa > $pb);
    }

    /** latest, or a "major.minor" line such as 3.1. */
    public static function onChannel(string $version, string $channel): bool
    {
        if ($channel === 'latest') {
            return true;
        }
        $p = self::parse($version);

        return $p !== null && $channel === $p[0].'.'.$p[1];
    }

    /** Newer tags on the nodes, newest first. */
    public function available(EnterpriseDeployment $dep): array
    {
        $all = $this->deployments->versions($dep->organization_id)['versions'] ?? [];

        return collect($all)->filter(fn ($v) => self::newer((string) $v['version'], $dep->version))
            ->sort(fn ($x, $y) => self::parse($y['version']) <=> self::parse($x['version']))->values()->all();
    }

    // ------------------------------------------------------------------ settings

    public function settings(EnterpriseDeployment $dep): array
    {
        $s = array_replace_recursive(self::DEFAULTS, (array) ($dep->update_settings ?? []));
        $s['window']['days'] = array_values(array_map('intval', (array) $s['window']['days']));
        $s['restore_data_on_rollback'] = (bool) $s['restore_data_on_rollback'];

        return $s;
    }

    public function saveSettings(EnterpriseDeployment $dep, array $in, ?string $userId): array
    {
        $before = $this->settings($dep);
        $s = array_replace_recursive($before, array_intersect_key($in, array_flip(['mode', 'channel', 'window', 'notice_hours', 'restore_data_on_rollback'])));
        if (array_key_exists('restore_data_on_rollback', $in)) {
            $s['restore_data_on_rollback'] = (bool) $in['restore_data_on_rollback'];
        }
        if (isset($in['notice_hours'])) {
            $s['notice_hours'] = (int) $in['notice_hours'];
        }
        if (isset($in['window']['days'])) {
            $s['window']['days'] = array_values(array_unique(array_map('intval', $in['window']['days'])));
        }
        $dep->update(['update_settings' => $s]);
        $this->audit->log('enterprise.deployment.update.settings', 'EnterpriseDeployment', $dep->id,
            ['from' => array_diff_key($before, self::INTERNAL), 'to' => array_diff_key($s, self::INTERNAL), 'external_id' => $dep->external_id], $dep->organization_id, $userId);

        return $this->settings($dep->fresh());
    }

    /** True when $at falls inside the deployment's maintenance window (in the window's own time zone). */
    public static function inWindow(array $window, Carbon $at): bool
    {
        return self::openedAt($window, $at) !== null;
    }

    /** When the window $at falls inside opened (UTC), or null outside every window. */
    public static function openedAt(array $window, Carbon $at): ?Carbon
    {
        $tz = $window['timezone'] ?? 'UTC';
        [$h, $m] = array_map('intval', explode(':', (string) ($window['start'] ?? '02:00')) + [1 => 0]);
        $local = $at->copy()->setTimezone($tz);
        foreach ([0, 1] as $back) { // a window that starts yesterday may still be open after midnight
            $start = $local->copy()->subDays($back)->setTime($h, $m);
            if (in_array($start->dayOfWeekIso, (array) ($window['days'] ?? []), true)
                && $local->gte($start) && $local->lt($start->copy()->addMinutes((int) ($window['duration_minutes'] ?? 120)))) {
                return $start->utc();
            }
        }

        return null;
    }

    /**
     * The first window the customer can still be told about $hours ahead: the next opening after $at whose notice
     * time (opening − $hours) has not passed. A window announced too late is skipped, never run unannounced.
     */
    public static function noticeWindow(array $window, int $hours, Carbon $at): ?Carbon
    {
        $w = self::nextWindow($window, $at);
        for ($i = 0; $w && $i < 60 && $w->copy()->subHours($hours)->lt($at); $i++) {
            $w = self::nextWindow($window, $w);
        }

        return $w;
    }

    /** The next window opening after $at (UTC), or null when no day is chosen. */
    public static function nextWindow(array $window, Carbon $at): ?Carbon
    {
        if (empty($window['days'])) {
            return null;
        }
        $tz = $window['timezone'] ?? 'UTC';
        [$h, $m] = array_map('intval', explode(':', (string) ($window['start'] ?? '02:00')) + [1 => 0]);
        $local = $at->copy()->setTimezone($tz);
        for ($d = 0; $d <= 7; $d++) {
            $start = $local->copy()->addDays($d)->setTime($h, $m);
            if ($start->gt($local) && in_array($start->dayOfWeekIso, (array) $window['days'], true)) {
                return $start->utc();
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ the tab

    public function state(EnterpriseDeployment $dep): array
    {
        $runs = EnterpriseUpdate::where('enterprise_deployment_id', $dep->id)->latest()->limit(50)->get();
        $settings = $this->settings($dep);
        $available = $this->available($dep);

        return [
            'deployment_id' => $dep->id,
            'current_version' => $dep->version,
            'image' => EnterpriseProvisioningService::image((string) $dep->version),
            'status' => $dep->status,
            'can_update' => $this->updatable($dep) === null,
            'blocked_reason' => $this->updatable($dep),
            'available' => $available,
            'latest' => collect($available)->first(fn ($v) => self::onChannel($v['version'], $settings['channel']))['version'] ?? null,
            'settings' => array_diff_key($settings, self::INTERNAL),
            'next_window' => $settings['mode'] === 'auto' ? self::nextWindow($settings['window'], now())?->toIso8601ZuluString() : null,
            'next_notice' => $this->nextNotice($dep, $settings, $available),
            'active' => ($a = $runs->first(fn ($r) => in_array($r->status, EnterpriseUpdate::ACTIVE, true))) ? $this->present($a) : null,
            'last' => ($l = $runs->first(fn ($r) => in_array($r->status, EnterpriseUpdate::TERMINAL, true) && $r->status !== 'cancelled')) ? $this->present($l) : null,
            'history' => $runs->map(fn ($r) => $this->present($r))->values(),
        ];
    }

    public function present(EnterpriseUpdate $u): array
    {
        return [
            'id' => $u->id, 'from_version' => $u->from_version, 'to_version' => $u->to_version, 'trigger' => $u->trigger, 'status' => $u->status,
            'scheduled_for' => $u->scheduled_for?->toIso8601ZuluString(), 'started_at' => $u->started_at?->toIso8601ZuluString(),
            'finished_at' => $u->finished_at?->toIso8601ZuluString(), 'created_at' => $u->created_at?->toIso8601ZuluString(),
            'backup_id' => $u->backup_job_id,
            'backup_status' => $u->backup_job_id ? BackupJob::allTenants()->whereKey($u->backup_job_id)->value('status') : null,
            'requested_by' => $u->requested_by_name, 'reason' => $u->reason, 'error' => $u->error, 'log' => array_values((array) $u->log),
            'data_restore' => $u->data_restore ?: null,
            // True only when an automatic rollback also put the pre-update data back.
            'data_restored' => ($u->data_restore['state'] ?? null) === 'succeeded',
            'can_cancel' => $u->status === 'scheduled',
            'can_restore' => $u->backup_job_id !== null && in_array($u->status, ['failed', 'rolled_back', 'succeeded'], true),
        ];
    }

    private function updatable(EnterpriseDeployment $dep): ?string
    {
        if (! in_array($dep->status, ['active', 'awaiting_setup', 'configuring'], true) || ! $dep->app_application_id) {
            return "The deployment is {$dep->status}; it can be updated once it is running.";
        }
        if (! $dep->project_id || ! Project::allTenants()->whereKey($dep->project_id)->exists()) {
            return 'The deployment has no panel project to back up.';
        }

        return null;
    }

    // ------------------------------------------------------------------ actions

    /**
     * Start (at null / a past time) or schedule an update. One active run per deployment; the target tag must be
     * loaded on the deployment's node. $trigger auto is idempotent per target version.
     */
    public function request(EnterpriseDeployment $dep, string $version, ?Carbon $at, string $trigger, ?User $user, ?string $reason = null): EnterpriseUpdate
    {
        if ($why = $this->updatable($dep)) {
            throw ApiException::conflict($why);
        }
        if ($version === $dep->version) {
            throw ApiException::validation(['version' => ["The deployment already runs {$version}."]]);
        }
        $loaded = collect($this->deployments->versions($dep->organization_id)['versions'] ?? [])->firstWhere('version', $version);
        if (! $loaded) {
            throw ApiException::validation(['version' => [EnterpriseProvisioningService::image($version).' is not loaded on the enterprise nodes.']]);
        }
        if ($trigger === 'auto' && ($done = EnterpriseUpdate::where('enterprise_deployment_id', $dep->id)->where('to_version', $version)->where('dedupe_key', 'auto')->first())) {
            return $done;
        }
        if ($active = EnterpriseUpdate::where('enterprise_deployment_id', $dep->id)->whereIn('status', EnterpriseUpdate::ACTIVE)->first()) {
            throw ApiException::conflict("An update to {$active->to_version} is already {$active->status}. Cancel it first.");
        }
        $run = EnterpriseUpdate::create([
            'organization_id' => $dep->organization_id, 'enterprise_deployment_id' => $dep->id,
            'from_version' => $dep->version, 'to_version' => $version, 'trigger' => $trigger,
            'dedupe_key' => $trigger === 'auto' ? 'auto' : (string) Str::uuid(), 'status' => 'scheduled',
            'scheduled_for' => $at && $at->isFuture() ? $at->utc() : now(), 'requested_by' => $user?->id,
            'requested_by_name' => $user?->name ?? ($trigger === 'auto' ? 'Auto-update' : null), 'reason' => $reason,
            'log' => [],
        ]);
        $this->note($run, $run->scheduled_for->isFuture() ? 'Scheduled for '.$run->scheduled_for->toIso8601ZuluString() : 'Requested');
        $this->audit->log('enterprise.deployment.update.request', 'EnterpriseDeployment', $dep->id,
            ['update_id' => $run->id, 'from' => $run->from_version, 'to' => $version, 'trigger' => $trigger, 'scheduled_for' => $run->scheduled_for->toIso8601ZuluString(), 'reason' => $reason, 'external_id' => $dep->external_id],
            $dep->organization_id, $user?->id);
        $this->event($dep, $run);
        if (! $run->scheduled_for->isFuture()) {
            $this->advance($run);
        }

        return $run->fresh();
    }

    public function cancel(EnterpriseDeployment $dep, string $id, ?User $user): EnterpriseUpdate
    {
        $run = $this->runOf($dep, $id);
        if ($run->status !== 'scheduled') {
            throw ApiException::conflict("This update is {$run->status}; only a scheduled update can be cancelled.");
        }
        $run->update(['status' => 'cancelled', 'finished_at' => now()]);
        $this->note($run, 'Cancelled'.($user ? ' by '.$user->name : ''));
        $this->audit->log('enterprise.deployment.update.cancel', 'EnterpriseDeployment', $dep->id, ['update_id' => $run->id, 'to' => $run->to_version, 'external_id' => $dep->external_id], $dep->organization_id, $user?->id);
        $this->event($dep, $run);

        return $run->fresh();
    }

    /**
     * Put the deployment back as it was before a run: redeploy the run's previous tag (when it differs) and
     * restore its pre-update backup (database + volumes). ProjectBackupService takes a fresh recovery point
     * of the current state first.
     */
    public function restore(EnterpriseDeployment $dep, string $id, ?User $user, string $reason): array
    {
        $run = $this->runOf($dep, $id);
        if (! $run->backup_job_id || ! in_array($run->status, ['failed', 'rolled_back', 'succeeded'], true)) {
            throw ApiException::conflict('This update has no finished pre-update backup to restore.');
        }
        if (EnterpriseUpdate::where('enterprise_deployment_id', $dep->id)->whereIn('status', EnterpriseUpdate::ACTIVE)->exists()) {
            throw ApiException::conflict('An update is in progress; wait for it to finish.');
        }
        $backup = BackupJob::allTenants()->findOrFail($run->backup_job_id);
        $redeploy = null;
        if ($run->from_version && $dep->version !== $run->from_version) {
            $redeploy = $this->deployments->act($dep, 'redeploy', ['version' => $run->from_version]);
        }
        $out = $this->tenant->as($dep->organization_id, fn () => $this->backups->restore($backup, $user?->id, 'project'));
        $this->note($run, 'Restore of the pre-update backup started'.($user ? ' by '.$user->name : '').': '.$reason);
        $this->audit->log('enterprise.deployment.update.restore', 'EnterpriseDeployment', $dep->id,
            ['update_id' => $run->id, 'backup_id' => $backup->id, 'version' => $run->from_version, 'reason' => $reason, 'external_id' => $dep->external_id], $dep->organization_id, $user?->id);
        $this->deployments->enqueue($dep->fresh(), 'deployment.update', ['update' => $this->present($run->fresh()), 'restore' => ['backup_id' => $backup->id, 'restores' => count($out['restored'])]]);

        return ['update' => $this->present($run->fresh()), 'redeploy' => $redeploy, 'restore' => $out];
    }

    private function runOf(EnterpriseDeployment $dep, string $id): EnterpriseUpdate
    {
        return EnterpriseUpdate::where('enterprise_deployment_id', $dep->id)->whereKey($id)->first()
            ?? throw new ApiException('update_not_found', 'No such update of this deployment.', 404);
    }

    // ------------------------------------------------------------------ the scheduler

    /** One pass: auto/notify checks for every deployment, then every due or running update. Returns runs advanced. */
    public function tick(): int
    {
        $this->tenant->set(null);
        EnterpriseDeployment::whereIn('status', ['active', 'awaiting_setup', 'configuring'])->whereNotNull('update_settings')->chunkById(50, function ($deps) {
            foreach ($deps as $dep) {
                rescue(fn () => $this->policy($dep), report: true);
            }
        });
        $n = 0;
        EnterpriseUpdate::whereIn('status', EnterpriseUpdate::ACTIVE)->where(fn ($q) => $q->where('status', '!=', 'scheduled')->orWhere('scheduled_for', '<=', now()))
            ->orderBy('scheduled_for')->get()->each(function (EnterpriseUpdate $run) use (&$n) {
                rescue(function () use ($run, &$n) {
                    $this->advance($run);
                    $n++;
                }, report: true);
            });

        return $n;
    }

    /**
     * notify: tell Cloud Admin once per new version. auto: start one run per version, inside the window only. With
     * notice_hours > 0 an auto run also needs the window to have been announced (deployment.update_scheduled, which
     * Cloud turns into the customer email) at least notice_hours before it opened; otherwise that window is skipped.
     */
    private function policy(EnterpriseDeployment $dep): void
    {
        $s = $this->settings($dep);
        if ($s['mode'] === 'off') {
            return;
        }
        $target = collect($this->available($dep))->first(fn ($v) => self::onChannel($v['version'], $s['channel']))['version'] ?? null;
        if (! $target) {
            return;
        }
        if ($s['notified_version'] !== $target) {
            $dep->update(['update_settings' => array_merge((array) $dep->update_settings, ['notified_version' => $target])]);
            $this->deployments->enqueue($dep->fresh(), 'deployment.update_available', ['update_available' => ['version' => $target, 'current_version' => $dep->version, 'mode' => $s['mode']]]);
            $this->audit->log('enterprise.deployment.update.available', 'EnterpriseDeployment', $dep->id, ['version' => $target, 'external_id' => $dep->external_id], $dep->organization_id);
        }
        if ($s['mode'] !== 'auto' || EnterpriseUpdate::where('enterprise_deployment_id', $dep->id)->whereIn('status', EnterpriseUpdate::ACTIVE)->exists()) {
            return;
        }
        $hours = (int) $s['notice_hours'];
        $opened = self::openedAt($s['window'], now());
        if ($opened && ($hours === 0 || $this->announcedFor($s, $target, $opened))) {
            $this->request($dep, $target, null, 'auto', null, 'Automatic update in the maintenance window');

            return;
        }
        if ($hours > 0 && ! $this->updatable($dep) && ! $this->autoRan($dep, $target)) {
            $this->announce($dep, $s, $target);
        }
    }

    private function announcedFor(array $s, string $target, Carbon $windowStart): bool
    {
        $a = (array) ($s['announced'] ?? []);

        return ($a['version'] ?? null) === $target && ($a['window_start'] ?? null) === $windowStart->toIso8601ZuluString();
    }

    private function autoRan(EnterpriseDeployment $dep, string $version): bool
    {
        return EnterpriseUpdate::where('enterprise_deployment_id', $dep->id)->where('to_version', $version)->where('dedupe_key', 'auto')->exists();
    }

    /** Send deployment.update_scheduled once per (version, window). */
    private function announce(EnterpriseDeployment $dep, array $s, string $target): void
    {
        $hours = (int) $s['notice_hours'];
        $w = self::noticeWindow($s['window'], $hours, now());
        if (! $w || $this->announcedFor($s, $target, $w)) {
            return;
        }
        $a = ['version' => $target, 'window_start' => $w->toIso8601ZuluString(), 'at' => now()->toIso8601ZuluString(), 'notice_hours' => $hours, 'notify_at' => $w->copy()->subHours($hours)->toIso8601ZuluString()];
        $dep->update(['update_settings' => array_merge((array) $dep->update_settings, ['announced' => $a])]);
        $this->deployments->enqueue($dep->fresh(), 'deployment.update_scheduled', ['update_scheduled' => [
            'version' => $target, 'current_version' => $dep->version, 'window_start' => $a['window_start'],
            'window_end' => $w->copy()->addMinutes((int) $s['window']['duration_minutes'])->toIso8601ZuluString(),
            'timezone' => $s['window']['timezone'], 'notice_hours' => $hours, 'notify_at' => $a['notify_at'],
        ]]);
        $this->audit->log('enterprise.deployment.update.scheduled', 'EnterpriseDeployment', $dep->id, ['version' => $target, 'window_start' => $a['window_start'], 'notice_hours' => $hours, 'external_id' => $dep->external_id], $dep->organization_id);
    }

    /** For the tab: the auto update the customer will be emailed about, or null. */
    private function nextNotice(EnterpriseDeployment $dep, array $s, array $available): ?array
    {
        $hours = (int) $s['notice_hours'];
        $target = collect($available)->first(fn ($v) => self::onChannel($v['version'], $s['channel']))['version'] ?? null;
        if ($s['mode'] !== 'auto' || $hours === 0 || ! $target || $this->autoRan($dep, $target)) {
            return null;
        }
        $a = (array) ($s['announced'] ?? []);
        if (($a['version'] ?? null) === $target && isset($a['window_start']) && Carbon::parse($a['window_start'])->isFuture()) {
            return ['version' => $target, 'window_start' => $a['window_start'], 'notify_at' => $a['notify_at'] ?? null, 'announced' => true];
        }
        $w = self::noticeWindow($s['window'], $hours, now());

        return $w ? ['version' => $target, 'window_start' => $w->toIso8601ZuluString(), 'notify_at' => $w->copy()->subHours($hours)->toIso8601ZuluString(), 'announced' => false] : null;
    }

    /** Moves one run forward as far as the current facts allow. Safe to call repeatedly. */
    public function advance(EnterpriseUpdate $run): void
    {
        $run->refresh();
        $dep = EnterpriseDeployment::find($run->enterprise_deployment_id);
        if (! $dep || in_array($run->status, EnterpriseUpdate::TERMINAL, true)) {
            return;
        }
        $cfg = (array) config('enterprise.updates');
        match ($run->status) {
            'scheduled' => $this->begin($dep, $run),
            'backing_up' => $this->afterBackup($dep, $run, (int) ($cfg['backup_timeout_minutes'] ?? 60)),
            'deploying' => $this->afterDeploy($dep, $run, (int) ($cfg['deploy_timeout_minutes'] ?? 20), false),
            'rolling_back' => $this->afterDeploy($dep, $run, (int) ($cfg['deploy_timeout_minutes'] ?? 20), true),
            'restoring_data' => $this->afterDataRestore($dep, $run, (int) ($cfg['restore_timeout_minutes'] ?? 90)),
            default => null,
        };
    }

    private function begin(EnterpriseDeployment $dep, EnterpriseUpdate $run): void
    {
        if ($run->scheduled_for?->isFuture()) {
            return;
        }
        if ($why = $this->updatable($dep)) {
            $this->finish($dep, $run, 'failed', $why.' Nothing was changed.');

            return;
        }
        if ($dep->version === $run->to_version) {
            $this->finish($dep, $run, 'succeeded', null, 'Already on '.$run->to_version);

            return;
        }
        $run->update(['status' => 'backing_up', 'started_at' => now(), 'from_version' => $dep->version]);
        try {
            $backup = $this->tenant->as($dep->organization_id, fn () => $this->backups->backup(Project::findOrFail($dep->project_id), 'pre_update'));
        } catch (\Throwable $e) {
            $this->finish($dep, $run, 'failed', 'The pre-update backup could not start: '.$e->getMessage().' Nothing was changed.');

            return;
        }
        $run->update(['backup_job_id' => $backup->id]);
        $this->note($run, 'Backing up the database and data volumes');
        $this->event($dep, $run);
        $this->afterBackup($dep, $run->fresh(), (int) config('enterprise.updates.backup_timeout_minutes', 60));
    }

    private function afterBackup(EnterpriseDeployment $dep, EnterpriseUpdate $run, int $timeout): void
    {
        $this->backups->rollup((string) $run->backup_job_id);
        $backup = BackupJob::allTenants()->find($run->backup_job_id);
        $status = $backup?->status;
        if ($status === 'succeeded') {
            $this->note($run, 'Backup finished');
            $run->update(['status' => 'deploying', 'deploy_started_at' => now()]);
            try {
                $run->update(['job_ids' => $this->deployments->act($dep, 'redeploy', ['version' => $run->to_version])['job_ids']]);
            } catch (\Throwable $e) {
                $this->finish($dep, $run, 'failed', 'The redeploy could not start: '.$e->getMessage());

                return;
            }
            $this->note($run, 'Redeploying on '.EnterpriseProvisioningService::image($run->to_version));
            $this->event($dep, $run);

            return;
        }
        if (in_array($status, ['failed', 'partial'], true) || ! $backup || $run->started_at?->lt(now()->subMinutes($timeout))) {
            $this->finish($dep, $run, 'failed', 'The pre-update backup '.($status === 'running' ? 'timed out' : 'failed').($backup?->error ? ': '.$backup->error : '').'. Nothing was changed.');
        }
    }

    /** The redeploy's own Deployment rows (by job id): ok | failed | null (still running). */
    private function outcome(EnterpriseUpdate $run): ?string
    {
        $rows = Deployment::whereIn('job_id', (array) ($run->job_ids ?? []))->get();
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->contains(fn ($d) => in_array($d->status, ['failed', 'rolled_back', 'cancelled'], true))) {
            return 'failed';
        }

        return $rows->count() >= count((array) $run->job_ids) && $rows->every(fn ($d) => $d->status === 'succeeded') ? 'ok' : null;
    }

    private function afterDeploy(EnterpriseDeployment $dep, EnterpriseUpdate $run, int $timeout, bool $rollback): void
    {
        $since = $rollback ? $run->rollback_started_at : $run->deploy_started_at;
        $result = $this->outcome($run);
        if ($result === null && $since?->lt(now()->subMinutes($timeout))) {
            $result = 'timeout';
        }
        if ($result === null) {
            return;
        }
        if (! $rollback) {
            if ($result === 'ok') {
                $this->finish($dep, $run, 'succeeded', null, 'Healthy on '.$run->to_version);

                return;
            }
            $why = $result === 'timeout' ? 'The new version did not become healthy in time.' : 'A container did not become healthy on the new version.';
            $run->update(['status' => 'rolling_back', 'rollback_started_at' => now(), 'error' => $why]);
            $this->note($run, $why.' Rolling back to '.$run->from_version);
            $this->event($dep, $run);
            try {
                $run->update(['job_ids' => $this->deployments->act($dep->fresh(), 'redeploy', ['version' => $run->from_version])['job_ids']]);
            } catch (\Throwable $e) {
                $this->finish($dep, $run, 'failed', $why.' The rollback could not start: '.$e->getMessage().' Restore the pre-update backup.');
            }

            return;
        }
        if ($result === 'ok') {
            $this->note($run, 'Running '.$run->from_version.' again');
            $this->restoreData($dep, $run->fresh());
        } else {
            $this->finish($dep, $run, 'failed', trim($run->error.' The rollback to '.$run->from_version.' failed too. Restore the pre-update backup.'));
        }
    }

    /** After a healthy rollback: restore the pre-update backup too (setting on), or finish as rolled back. */
    private function restoreData(EnterpriseDeployment $dep, EnterpriseUpdate $run): void
    {
        if (! $this->settings($dep)['restore_data_on_rollback']) {
            $run->update(['data_restore' => ['state' => 'skipped', 'reason' => 'setting_off']]);
            $this->finish($dep, $run, 'rolled_back', $run->error, 'Rolled back: running '.$run->from_version.' again. The data was not restored (restore_data_on_rollback is off); restore the pre-update backup if the new version changed it.');

            return;
        }
        $backup = $run->backup_job_id ? BackupJob::allTenants()->find($run->backup_job_id) : null;
        try {
            if (! $backup) {
                throw new \RuntimeException('the pre-update backup is missing');
            }
            $out = $this->tenant->as($dep->organization_id, fn () => $this->backups->restore($backup, null, 'project'));
        } catch (\Throwable $e) {
            $this->dataRestoreFailed($dep, $run, ['backup_id' => $run->backup_job_id, 'started_at' => now()->toIso8601ZuluString()], 'The data restore could not start: '.$e->getMessage());

            return;
        }
        $run->update(['status' => 'restoring_data', 'data_restore' => [
            'state' => 'running', 'backup_id' => $backup->id, 'started_at' => now()->toIso8601ZuluString(),
            'restore_job_ids' => array_column($out['restored'], 'restore_job_id'), 'safety_backup_ids' => [], 'skipped' => $out['skipped'],
        ]]);
        $this->note($run, 'Restoring the pre-update backup (database + files), after a fresh safety backup of the current state');
        $this->audit->log('enterprise.deployment.update.data_restore', 'EnterpriseDeployment', $dep->id,
            ['update_id' => $run->id, 'backup_id' => $backup->id, 'state' => 'running', 'restores' => count($out['restored']), 'automatic' => true, 'external_id' => $dep->external_id], $dep->organization_id);
        $this->event($dep, $run);
        $this->afterDataRestore($dep, $run->fresh(), (int) config('enterprise.updates.restore_timeout_minutes', 90));
    }

    private function afterDataRestore(EnterpriseDeployment $dep, EnterpriseUpdate $run, int $timeout): void
    {
        $info = (array) $run->data_restore;
        $jobs = RestoreJob::allTenants()->whereIn('id', (array) ($info['restore_job_ids'] ?? []))->get();
        $info['safety_backup_ids'] = $jobs->pluck('pre_restore_backup_job_id')->filter()->values()->all();
        $started = isset($info['started_at']) ? Carbon::parse($info['started_at']) : $run->updated_at;
        if ($jobs->contains(fn ($j) => $j->status === 'failed') || $jobs->isEmpty()) {
            $this->dataRestoreFailed($dep, $run, $info, 'The data restore failed'.(($m = $jobs->where('status', 'failed')->pluck('message')->filter()->first()) ? ': '.$m : '').'.');

            return;
        }
        if ($jobs->every(fn ($j) => $j->status === 'succeeded')) {
            $run->update(['data_restore' => array_merge($info, ['state' => 'succeeded', 'finished_at' => now()->toIso8601ZuluString()])]);
            $this->audit->log('enterprise.deployment.update.data_restore', 'EnterpriseDeployment', $dep->id,
                ['update_id' => $run->id, 'backup_id' => $info['backup_id'] ?? null, 'state' => 'succeeded', 'safety_backup_ids' => $info['safety_backup_ids'], 'external_id' => $dep->external_id], $dep->organization_id);
            $this->finish($dep, $run, 'rolled_back', $run->error, 'Rolled back: running '.$run->from_version.' again, and the data was restored to before the update.');

            return;
        }
        if ($started?->lt(now()->subMinutes($timeout))) {
            $this->dataRestoreFailed($dep, $run, $info, 'The data restore did not finish in time.');

            return;
        }
        if ($info !== (array) $run->data_restore) {
            $run->update(['data_restore' => $info]);
        }
    }

    /** Rolled back, data restore failed: the previous version runs; the safety backups stay for a manual restore. */
    private function dataRestoreFailed(EnterpriseDeployment $dep, EnterpriseUpdate $run, array $info, string $why): void
    {
        $run->update(['data_restore' => array_merge($info, ['state' => 'failed', 'error' => $why, 'finished_at' => now()->toIso8601ZuluString()])]);
        $this->audit->log('enterprise.deployment.update.data_restore', 'EnterpriseDeployment', $dep->id,
            ['update_id' => $run->id, 'backup_id' => $info['backup_id'] ?? null, 'state' => 'failed', 'error' => $why, 'safety_backup_ids' => $info['safety_backup_ids'] ?? [], 'external_id' => $dep->external_id], $dep->organization_id);
        $this->finish($dep, $run, 'rolled_back', trim($run->error.' Rolled back, but '.lcfirst($why).' The safety backup of the state before the restore is kept.'),
            'Rolled back to '.$run->from_version.', data restore failed: '.$why.' The safety backup is kept; use Restore… to try again.');
    }

    private function finish(EnterpriseDeployment $dep, EnterpriseUpdate $run, string $status, ?string $error, ?string $line = null): void
    {
        $run->update(['status' => $status, 'error' => $error, 'finished_at' => now()]);
        $this->note($run, $line ?? ucfirst(str_replace('_', ' ', $status)).($error ? ': '.$error : ''));
        $this->audit->log('enterprise.deployment.update.'.$status, 'EnterpriseDeployment', $dep->id,
            ['update_id' => $run->id, 'from' => $run->from_version, 'to' => $run->to_version, 'trigger' => $run->trigger, 'error' => $error, 'data_restore' => $run->fresh()->data_restore['state'] ?? null, 'external_id' => $dep->external_id], $dep->organization_id);
        $this->event($dep, $run);
    }

    private function note(EnterpriseUpdate $run, string $line): void
    {
        $log = array_values((array) $run->log);
        $log[] = ['at' => now()->toIso8601ZuluString(), 'status' => $run->status, 'message' => Str::limit($line, 500)];
        $run->update(['log' => array_slice($log, -40)]);
    }

    private function event(EnterpriseDeployment $dep, EnterpriseUpdate $run): void
    {
        $this->deployments->enqueue($dep->fresh(), 'deployment.update', ['update' => $this->present($run->fresh())]);
    }
}
