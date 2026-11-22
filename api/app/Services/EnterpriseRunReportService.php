<?php

namespace App\Services;

use App\Models\Application;
use App\Models\BackupJob;
use App\Models\Database;
use App\Models\Deployment;
use App\Models\EnterpriseDeployment;
use App\Models\EnterpriseRunReport;
use App\Models\EnterpriseUpdate;
use App\Models\Job;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ENT-MAIL (owner, 2026-11-22): an Enterprise deployment sends ONE email per run, after the whole run,
 * instead of one per container deploy and one per backup job.
 *
 *  - suppression: DeploymentService and BackupService ask ownsApplication()/ownsBackup() and skip their
 *    per-item emails (deploy_succeeded/failed, app_installed_admin, backup_failed) for enterprise items;
 *  - summaries: provisioning (awaiting_setup / active / failed), every finished update run
 *    (succeeded / rolled_back / failed, with the data restore), a manual redeploy / restart / start / stop,
 *    and a backup set of the deployment's project (manual always; scheduled ones only when they fail);
 *  - once per run: enterprise_run_reports.run_key is unique and emailed_at is claimed atomically;
 *  - recipients: the organisation members who received the per-item emails (deployments + backups roles),
 *    and staff for failures when the organisation is not the platform's (as deploy_failed did). Preferences:
 *    opting out of the summary, or of every per-item email it replaces, stops it.
 */
class EnterpriseRunReportService
{
    public const SUCCEEDED = 'enterprise_run_succeeded';

    public const FAILED = 'enterprise_run_failed';

    /** Per-item emails a summary replaces: a user who opted out of all of them does not get the summary. */
    private const REPLACES = [self::SUCCEEDED => ['deploy_succeeded'], self::FAILED => ['deploy_failed', 'backup_failed']];

    /** Scheduled backup sets older than this are never reported (no burst of old runs on the first deploy). */
    private const BACKUP_LOOKBACK_HOURS = 6;

    public function __construct(private EmailTemplateService $emails) {}

    // ------------------------------------------------------------------ suppression

    public static function ownsApplication(?string $applicationId): bool
    {
        return $applicationId !== null && EnterpriseDeployment::where(fn ($q) => $q->where('app_application_id', $applicationId)->orWhere('worker_application_id', $applicationId))->exists();
    }

    public static function ownsBackup(BackupJob $backup): bool
    {
        if ($backup->project_id && EnterpriseDeployment::where('project_id', $backup->project_id)->exists()) {
            return true;
        }
        if ($backup->parent_id && ($parent = BackupJob::allTenants()->find($backup->parent_id)) && $parent->project_id && EnterpriseDeployment::where('project_id', $parent->project_id)->exists()) {
            return true;
        }

        return match ($backup->target_type) {
            'application' => self::ownsApplication($backup->target_id),
            'database' => $backup->target_id !== null && EnterpriseDeployment::where(fn ($q) => $q->where('database_id', $backup->target_id)->orWhere('redis_id', $backup->target_id))->exists(),
            'project' => $backup->target_id !== null && EnterpriseDeployment::where('project_id', $backup->target_id)->exists(),
            default => false,
        };
    }

    // ------------------------------------------------------------------ runs

    /** A manual run started from the deployment (redeploy/restart/start/stop, or a backup set): watched by sweep(). */
    public function started(EnterpriseDeployment $dep, string $kind, array $jobIds = [], ?User $user = null, ?string $reason = null, ?string $backupJobId = null): EnterpriseRunReport
    {
        return EnterpriseRunReport::create([
            'organization_id' => $dep->organization_id, 'enterprise_deployment_id' => $dep->id, 'kind' => $kind,
            'run_key' => $backupJobId ? 'backup:'.$backupJobId : $kind.':'.Str::uuid(), 'status' => 'running',
            'triggered_by' => $user?->name ?? $user?->email, 'reason' => $reason ? Str::limit($reason, 480) : null,
            'job_ids' => array_values(array_filter($jobIds)), 'backup_job_id' => $backupJobId, 'started_at' => now(),
        ]);
    }

    /** EnterpriseProvisioningService::setStatus: a provisioning or setup run ended. */
    public function provisioningFinished(EnterpriseDeployment $dep, ?string $from, string $to): void
    {
        if (! in_array($from, ['queued', 'provisioning', 'configuring'], true) || ! in_array($to, ['awaiting_setup', 'active', 'failed'], true)) {
            return;
        }
        $failedStep = collect($dep->steps ?? [])->firstWhere('status', 'failed');
        // A retried step that fails again is a new run; a success is reported once per state.
        $key = 'provision:'.$dep->id.':'.$to.($to === 'failed' ? ':'.($failedStep['name'] ?? '').':'.($failedStep['finished_at'] ?? now()->toIso8601ZuluString()) : '');
        $started = collect($dep->steps ?? [])->pluck('started_at')->filter()->sort()->first();
        $report = $this->report($dep, $key, $to === 'configuring' ? 'setup' : 'provision', [
            'status' => $to === 'failed' ? 'failed' : 'succeeded', 'started_at' => $started ? Carbon::parse($started) : $dep->created_at, 'finished_at' => now(),
        ]);
        $this->deliver($report, $dep, fn () => $this->provisionContent($dep, $from, $to));
    }

    /** EnterpriseUpdateService::finish: an update run reached succeeded / rolled_back / failed. */
    public function updateFinished(EnterpriseUpdate $run): void
    {
        $run->refresh();
        $dep = EnterpriseDeployment::find($run->enterprise_deployment_id);
        if (! $dep || ! in_array($run->status, ['succeeded', 'rolled_back', 'failed'], true)) {
            return;
        }
        $report = $this->report($dep, 'update:'.$run->id, 'update', [
            'status' => $run->status, 'triggered_by' => $run->requested_by_name ?: ucfirst((string) $run->trigger), 'reason' => $run->reason,
            'backup_job_id' => $run->backup_job_id, 'started_at' => $run->started_at ?? $run->created_at, 'finished_at' => $run->finished_at ?? now(),
        ]);
        $this->deliver($report, $dep, fn () => $this->updateContent($dep, $run));
    }

    /** Scheduler (enterprise:sync): settles manual runs and backup sets, then emails the finished ones. */
    public function sweep(): int
    {
        $sent = 0;
        $this->discoverBackups();
        EnterpriseRunReport::where('status', 'running')->whereNull('emailed_at')->orderBy('created_at')->limit(200)->get()->each(function (EnterpriseRunReport $r) use (&$sent) {
            try {
                $dep = EnterpriseDeployment::find($r->enterprise_deployment_id);
                $status = $dep ? $this->settle($r) : 'failed';
                if ($status === null) {
                    return;
                }
                $r->update(['status' => $status, 'finished_at' => now()]);
                if ($dep && ! ($r->kind === 'backup' && $status === 'succeeded' && $this->isScheduledBackup($r))) {
                    $sent += $this->deliver($r, $dep, fn () => $r->kind === 'backup' ? $this->backupContent($dep, $r) : $this->actionContent($dep, $r)) ? 1 : 0;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        return $sent;
    }

    /** Backup sets of enterprise projects nobody registered (scheduled, or started in the panel): watch them too. */
    private function discoverBackups(): void
    {
        $deps = EnterpriseDeployment::whereNotNull('project_id')->whereNotIn('status', ['deleted', 'purged'])->get()->keyBy('project_id');
        if ($deps->isEmpty()) {
            return;
        }
        BackupJob::allTenants()->where('target_type', 'project')->whereIn('project_id', $deps->keys())->where('trigger', '!=', 'pre_update')
            ->where('created_at', '>=', now()->subHours(self::BACKUP_LOOKBACK_HOURS))->get()
            ->each(function (BackupJob $b) use ($deps) {
                if (EnterpriseRunReport::where('run_key', 'backup:'.$b->id)->exists()) {
                    return;
                }
                $dep = $deps[$b->project_id];
                try {
                    EnterpriseRunReport::create([
                        'organization_id' => $dep->organization_id, 'enterprise_deployment_id' => $dep->id, 'kind' => 'backup', 'run_key' => 'backup:'.$b->id,
                        'status' => 'running', 'triggered_by' => $b->trigger === 'manual' ? null : 'Backup schedule', 'backup_job_id' => $b->id, 'started_at' => $b->started_at ?? $b->created_at,
                    ]);
                } catch (QueryException) {
                    // registered meanwhile
                }
            });
    }

    private function isScheduledBackup(EnterpriseRunReport $r): bool
    {
        return BackupJob::allTenants()->whereKey($r->backup_job_id)->value('trigger') !== 'manual';
    }

    /** succeeded | failed | partial, or null while the run is still going. */
    private function settle(EnterpriseRunReport $r): ?string
    {
        if ($r->kind === 'backup') {
            app(ProjectBackupService::class)->rollup((string) $r->backup_job_id);
            $b = BackupJob::allTenants()->find($r->backup_job_id);
            if (! $b) {
                return 'failed';
            }

            return in_array($b->status, ['succeeded', 'failed', 'partial'], true) ? $b->status
                : ($r->started_at?->lt(now()->subMinutes((int) config('enterprise.updates.backup_timeout_minutes', 60) * 2)) ? 'failed' : null);
        }
        $ids = (array) $r->job_ids;
        $jobs = Job::withoutGlobalScopes()->whereIn('id', $ids)->get();
        $deploys = Deployment::withoutGlobalScopes()->whereIn('job_id', $ids)->get();
        $failed = $jobs->contains(fn ($j) => in_array($j->status, ['failed', 'cancelled'], true))
            || $deploys->contains(fn ($d) => in_array($d->status, ['failed', 'rolled_back', 'cancelled'], true));
        $done = $jobs->count() >= count($ids) && $jobs->every(fn ($j) => in_array($j->status, ['succeeded', 'failed', 'cancelled'], true));
        if ($done || ($failed && $jobs->every(fn ($j) => in_array($j->status, ['succeeded', 'failed', 'cancelled'], true)))) {
            return $failed ? 'failed' : 'succeeded';
        }

        return $r->started_at?->lt(now()->subMinutes((int) config('enterprise.updates.deploy_timeout_minutes', 20) * 2)) ? 'failed' : null;
    }

    // ------------------------------------------------------------------ delivery

    private function report(EnterpriseDeployment $dep, string $key, string $kind, array $attrs): EnterpriseRunReport
    {
        try {
            return EnterpriseRunReport::firstOrCreate(['run_key' => $key], ['organization_id' => $dep->organization_id, 'enterprise_deployment_id' => $dep->id, 'kind' => $kind] + $attrs);
        } catch (QueryException) {
            return EnterpriseRunReport::where('run_key', $key)->firstOrFail();
        }
    }

    /** Claims the run (once, atomically) and sends the summary. Never throws: a mail outage cannot fail the run. */
    private function deliver(EnterpriseRunReport $r, EnterpriseDeployment $dep, callable $content): bool
    {
        if (! EnterpriseRunReport::whereKey($r->id)->whereNull('emailed_at')->update(['emailed_at' => now()])) {
            return false;
        }
        try {
            $vars = $content();
            $key = $vars['_failed'] ? self::FAILED : self::SUCCEEDED;
            unset($vars['_failed']);
            $n = 0;
            foreach ($this->recipients($dep->organization_id, $key) as $user) {
                $n += $this->emails->notify($key, $user, $vars) ? 1 : 0;
            }
            $platform = \App\Models\Organization::orderBy('created_at')->orderBy('id')->value('id');
            if ($key === self::FAILED && $platform && $dep->organization_id !== $platform) {
                $n += $this->emails->notifyStaff($key, $vars, $r->run_key, 60 * 24 * 30);
            }
            $r->update(['recipients' => $n]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Enterprise run summary '.$r->run_key.' was not sent: '.$e->getMessage());

            return false;
        }
    }

    /** Members who received the per-item emails (deployments and backups groups), minus those who opted out. */
    public function recipients(?string $orgId, string $key): Collection
    {
        $roles = array_unique(array_merge(EmailTemplateService::RECIPIENTS['deployments'], EmailTemplateService::RECIPIENTS['default']));
        $users = User::whereIn('id', Membership::allTenants()->where('organization_id', $orgId)->where('status', 'active')->whereIn('role', $roles)->select('user_id'))->get();

        return $users->reject(function (User $u) use ($key) {
            $out = (array) ($u->email_preferences['opt_out'] ?? []);

            return array_diff(self::REPLACES[$key], $out) === [];
        })->values();
    }

    // ------------------------------------------------------------------ content

    private function provisionContent(EnterpriseDeployment $dep, ?string $from, string $to): array
    {
        $rows = [];
        $failures = [];
        foreach ((array) $dep->steps as $s) {
            if (($s['status'] ?? 'pending') === 'pending') {
                continue;
            }
            $rows[] = [$s['label'] ?? $s['name'], $s['status'], (string) ($s['message'] ?? '')];
            if ($s['status'] === 'failed') {
                $failures[] = [$s['label'] ?? $s['name'], (string) ($s['message'] ?? 'Failed'), $s['hint'] ?? (isset($s['code']) ? (EnterpriseProvisioningService::HINTS[$s['code']] ?? null) : null)];
            }
        }
        $started = collect($dep->steps ?? [])->pluck('started_at')->filter()->sort()->first();
        $what = $from === 'configuring' || $to === 'active' ? 'Setup' : 'Provisioning';
        $result = ['awaiting_setup' => 'Ready for the setup wizard', 'active' => 'Active', 'failed' => 'Failed'][$to];

        return $this->vars($dep, $what.' of '.$dep->hostname, $result, $to === 'failed', 'Cloud Admin', null, (string) $dep->version,
            $started ? Carbon::parse($started) : $dep->created_at, now(), $rows, $failures, null);
    }

    private function updateContent(EnterpriseDeployment $dep, EnterpriseUpdate $run): array
    {
        $rows = [];
        $failures = [];
        $backup = $run->backup_job_id ? BackupJob::allTenants()->find($run->backup_job_id) : null;
        $this->backupRows($backup, 'Pre-update backup', $rows, $failures);
        if (! $backup && $run->status === 'failed' && ! $run->deploy_started_at) {
            $rows[] = ['Pre-update backup', 'failed', (string) $run->error];
        }
        $this->deployRows($dep, $run->deploy_started_at, $run->finished_at ?? now(), $rows, $failures);
        if ($run->rollback_started_at) {
            $rows[] = ['Rollback to '.$run->from_version, $run->status === 'failed' ? 'failed' : 'succeeded', ''];
        }
        $restore = (array) ($run->data_restore ?? []);
        if ($restore) {
            $state = (string) ($restore['state'] ?? '');
            $rows[] = ['Data restore', $state, $state === 'skipped' ? 'restore_data_on_rollback is off' : (string) ($restore['error'] ?? '')];
            if ($state === 'failed') {
                $failures[] = ['Data restore', (string) ($restore['error'] ?? 'The data restore failed.'), 'The safety backup of the state before the restore is kept; use Restore… on the pre-update backup to try again.'];
            }
        }
        if ($run->status !== 'succeeded' && $failures === [] && $run->error) {
            $failures[] = ['Update', (string) $run->error, null];
        }
        $result = ['succeeded' => 'Succeeded', 'rolled_back' => 'Rolled back to '.$run->from_version, 'failed' => 'Failed'][$run->status];
        $log = collect((array) $run->log)->map(fn ($l) => Carbon::parse($l['at'])->format('H:i:s').'  '.$l['message'])->implode("\n");

        return $this->vars($dep, 'Update to '.$run->to_version, $result, $run->status !== 'succeeded', $run->requested_by_name ?: ucfirst((string) $run->trigger).' update',
            $run->reason, ($run->from_version ? $run->from_version.' → ' : '').$run->to_version, $run->started_at ?? $run->created_at, $run->finished_at ?? now(),
            $rows, $failures, $backup, $log);
    }

    private function actionContent(EnterpriseDeployment $dep, EnterpriseRunReport $r): array
    {
        $rows = [];
        $failures = [];
        $ids = (array) $r->job_ids;
        $deploys = Deployment::withoutGlobalScopes()->whereIn('job_id', $ids)->get()->keyBy('job_id');
        foreach (Job::withoutGlobalScopes()->whereIn('id', $ids)->get() as $job) {
            $d = $deploys[$job->id] ?? null;
            $name = $d ? (string) Application::allTenants()->whereKey($d->application_id)->value('name')
                : ($job->resource_type === 'container' ? (string) \App\Models\Container::allTenants()->whereKey($job->resource_id)->value('name') : '');
            $status = $d?->status ?? $job->status;
            $err = in_array($status, ['failed', 'rolled_back', 'cancelled'], true) ? Str::limit(trim((string) $job->error) ?: 'Failed', 300) : '';
            $rows[] = [trim(str_replace('.', ' ', (string) $job->type).' '.$name), $status, $err ?: $this->took($job->started_at, $job->finished_at)];
            if ($err) {
                $failures[] = [$name ?: $job->type, $err, $this->deployHint($err)];
            }
        }
        $label = ['redeploy' => 'Redeploy', 'restart' => 'Restart', 'start' => 'Start', 'stop' => 'Stop'][$r->kind] ?? ucfirst($r->kind);

        return $this->vars($dep, $label.' of '.$dep->hostname, $r->status === 'succeeded' ? 'Succeeded' : 'Failed', $r->status !== 'succeeded',
            $r->triggered_by ?: 'Cloud Admin', $r->reason, (string) $dep->version, $r->started_at ?? $r->created_at, $r->finished_at ?? now(), $rows, $failures, null);
    }

    private function backupContent(EnterpriseDeployment $dep, EnterpriseRunReport $r): array
    {
        $rows = [];
        $failures = [];
        $backup = BackupJob::allTenants()->find($r->backup_job_id);
        $this->backupRows($backup, 'Backup set', $rows, $failures);
        if (! $backup) {
            $failures[] = ['Backup set', 'The backup record is gone.', null];
        }
        $result = ['succeeded' => 'Succeeded', 'partial' => 'Partly failed', 'failed' => 'Failed'][$r->status] ?? ucfirst($r->status);

        return $this->vars($dep, 'Backup of '.$dep->hostname, $result, $r->status !== 'succeeded', $r->triggered_by ?: 'Cloud Admin', $r->reason,
            (string) $dep->version, $r->started_at ?? $r->created_at, $r->finished_at ?? now(), $rows, $failures, $backup);
    }

    /** The project backup and one row per backup job (application volumes, database dumps), with their errors. */
    private function backupRows(?BackupJob $parent, string $label, array &$rows, array &$failures): void
    {
        if (! $parent) {
            return;
        }
        $rows[] = [$label, (string) $parent->status, $this->took($parent->started_at, $parent->finished_at)];
        foreach (BackupJob::allTenants()->where('parent_id', $parent->id)->orderBy('created_at')->get() as $child) {
            $name = match ($child->target_type) {
                'application' => 'application '.(Application::allTenants()->whereKey($child->target_id)->value('name') ?? $child->target_id),
                'database' => 'database '.(Database::allTenants()->whereKey($child->target_id)->value('name') ?? $child->target_id),
                default => (string) $child->target_type,
            };
            $rows[] = ['  '.$name, (string) $child->status, $child->status === 'failed' ? (string) ($child->error ?: 'Backup failed') : ''];
            if ($child->status === 'failed') {
                $failures[] = ['Backup of '.$name, (string) ($child->error ?: 'Backup failed'), $this->backupHint((string) $child->error)];
            }
        }
        if (in_array($parent->status, ['failed', 'partial'], true) && BackupJob::allTenants()->where('parent_id', $parent->id)->where('status', 'failed')->doesntExist()) {
            $failures[] = [$label, (string) ($parent->error ?: 'The backup did not complete.'), EnterpriseProvisioningService::HINTS['BACKUP_FAILED']];
        }
    }

    /** One row per container deployment of the run (the web app and the worker, forward and rollback). */
    private function deployRows(EnterpriseDeployment $dep, ?Carbon $from, Carbon $to, array &$rows, array &$failures): void
    {
        if (! $from) {
            return;
        }
        $apps = Application::allTenants()->whereIn('id', array_filter([$dep->app_application_id, $dep->worker_application_id]))->pluck('name', 'id');
        $deploys = Deployment::withoutGlobalScopes()->whereIn('application_id', $apps->keys())->where('created_at', '>=', $from->copy()->subSecond())->where('created_at', '<=', $to->copy()->addSecond())->orderBy('created_at')->get();
        foreach ($deploys as $d) {
            $err = in_array($d->status, ['failed', 'rolled_back', 'cancelled'], true) ? Str::limit(trim((string) Job::withoutGlobalScopes()->whereKey($d->job_id)->value('error')) ?: 'The container did not become healthy.', 300) : '';
            $rows[] = ['Deploy '.$apps[$d->application_id], (string) $d->status, $err ?: $this->took($d->started_at, $d->finished_at)];
            if ($err) {
                $failures[] = ['Deploy '.$apps[$d->application_id], $err, $this->deployHint($err)];
            }
        }
    }

    private function backupHint(string $error): string
    {
        return EnterpriseProvisioningService::HINTS[stripos($error, 'restic') !== false && preg_match('/not installed|not found|no such file/i', $error) ? 'RESTIC_MISSING' : 'BACKUP_FAILED'];
    }

    private function deployHint(string $error): string
    {
        return EnterpriseProvisioningService::HINTS[preg_match('/No such image|manifest unknown|pull access denied|repository does not exist|image .* not found/i', $error) ? 'IMAGE_MISSING' : 'CONTAINER_UNHEALTHY'];
    }

    private function took(?Carbon $start, ?Carbon $end): string
    {
        return $start && $end ? 'in '.$this->duration($start, $end) : '';
    }

    private function duration(Carbon $start, Carbon $end): string
    {
        $s = max(0, (int) $start->diffInSeconds($end, true));

        return $s < 60 ? "{$s}s" : ($s < 3600 ? intdiv($s, 60).'m '.($s % 60).'s' : intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m');
    }

    /** The template variables. Failures (with their fix hints) come first in the body; the steps table is the code block. */
    private function vars(EnterpriseDeployment $dep, string $run, string $result, bool $failed, string $by, ?string $reason, string $versions,
        ?Carbon $start, Carbon $end, array $rows, array $failures, ?BackupJob $backup, string $log = ''): array
    {
        $fmt = fn (?Carbon $t) => $t ? $t->copy()->utc()->format('j M Y, H:i:s').' UTC' : '—';
        $width = max(12, ...array_map(fn ($r) => mb_strlen($r[0]), $rows ?: [['']]));
        $table = $rows ? implode("\n", array_merge(
            [str_pad('STEP', $width + 2).str_pad('RESULT', 13).'DETAIL'],
            array_map(fn ($r) => str_pad(Str::limit($r[0], 60, ''), $width + 2).str_pad($r[1], 13).Str::limit(preg_replace('/\s+/', ' ', $r[2]), 300), $rows),
        )) : 'No steps recorded.';
        if ($log !== '') {
            $table .= "\n\nRun log\n".$log;
        }
        $failText = implode("\n\n", array_map(fn ($f) => '✗ '.$f[0].': '.rtrim($f[1], '.').'.'.($f[2] ? "\nFix: ".$f[2] : ''), $failures));
        $recovery = $backup ? $backup->id.' · '.$backup->status.($backup->finished_at || $backup->started_at ? ' · recovery point '.$fmt($backup->finished_at ?? $backup->started_at) : '') : '—';
        $summary = $failed
            ? $run.' on '.$dep->hostname.' finished with the result: '.$result.'.'
            : $run.' on '.$dep->hostname.' finished successfully.';

        return [
            '_failed' => $failed,
            'deployment' => (string) $dep->hostname, 'run' => $run, 'result' => $result,
            'triggered_by' => $by, 'reason' => $reason ?: '—', 'versions' => $versions ?: '—',
            'started' => $fmt($start), 'finished' => $fmt($end), 'duration' => $start ? $this->duration($start, $end) : '—',
            'backup' => $recovery, 'failures' => $failText !== '' ? $failText : 'Nothing failed.', 'summary' => $summary, 'steps' => $table,
            'failure_count' => (string) count($failures), 'deployment_url' => $this->link($dep),
        ];
    }

    /** Cloud Admin's page for the deployment when configured (ENTERPRISE_ADMIN_URL with {id}/{external_id}), else the panel project. */
    public function link(EnterpriseDeployment $dep): string
    {
        $tpl = (string) config('enterprise.admin_url', '');
        if ($tpl !== '') {
            return strtr($tpl, ['{id}' => $dep->id, '{external_id}' => (string) $dep->external_id]);
        }

        return rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/').'/admin/projects/'.$dep->project_id;
    }
}
