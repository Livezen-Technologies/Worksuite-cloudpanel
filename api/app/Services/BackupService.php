<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Models\Database;
use App\Models\Job;
use App\Models\RestoreJob;
use App\Models\Secret;
use App\Models\Server;
use App\Models\Volume;
use App\Support\BackupDestination;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * BAK-001..006 / UBAK: policies, runs (restic via agent), application-consistent DB dumps,
 * integrity checks, scheduled restore tests, retention (restic forget --prune), exports, restores.
 */
class BackupService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    /**
     * Resolves a backup target.
     *
     * @return array{0: Server, 1: string[], 2: ?string, 3: ?array} server, paths, application id, dump spec
     */
    public function target(string $type, ?string $id): array
    {
        return match ($type) {
            'application' => (function () use ($id) {
                $app = Application::findOrFail($id);
                if (! $app->server) {
                    throw ApiException::conflict('This app has not been deployed yet, so there is nothing to back up.');
                }

                return [$app->server, ["/var/lib/docker/volumes/app-{$app->name}-data/_data"], $app->id, null];
            })(),
            'volume' => (function () use ($id) {
                $volume = Volume::findOrFail($id);

                return [$volume->server, [$volume->mountpoint ?: "/var/lib/docker/volumes/{$volume->name}/_data"], null, null];
            })(),
            'database' => (function () use ($id) {
                $db = Database::findOrFail($id);

                return [Server::findOrFail($db->server_id), [], null, $this->dumpSpec($db)];
            })(),
            'server' => [Server::findOrFail($id), ['/srv/dockermgr'], null, null],
            default => throw ApiException::validation(['target_type' => ['Unknown backup target.']]),
        };
    }

    /** BAK-002: an application-consistent dump through `docker exec` into the DB container. */
    public function dumpSpec(Database $db): array
    {
        $password = $db->secret_id ? Secret::find($db->secret_id)?->value : null;
        $user = match ($db->engine) {
            'postgres' => 'postgres', 'mysql', 'mariadb' => 'root', 'mongodb' => $password ? 'root' : '', default => '',
        };

        return array_filter([
            'engine' => $db->engine,
            'container' => 'db-'.Str::slug($db->name),
            'database' => $db->engine === 'postgres' ? 'postgres' : '',
            'user' => $user,
            'password' => (string) $password,
        ], fn ($v) => $v !== '');
    }

    public function destination(?BackupPolicy $policy, ?string $organizationId = null): BackupDestination
    {
        return new BackupDestination($policy, $organizationId ?? $policy?->organization_id ?? Tenant::current()->id);
    }

    private function scopeTag(?BackupPolicy $policy, string $targetType, ?string $targetId, ?string $applicationId): string
    {
        return $policy ? 'policy-'.$policy->id : ($applicationId ? 'app-'.$applicationId : "{$targetType}-{$targetId}");
    }

    public function run(?BackupPolicy $policy, string $targetType, ?string $targetId, string $trigger = 'manual', array $jobPayload = []): BackupJob
    {
        $targetType = $policy?->target_type ?? $targetType;
        $targetId = $policy?->target_id ?? $targetId;
        [$server, $paths, $applicationId, $dump] = $this->target($targetType, $targetId);
        $job = $this->jobs->create('backup', $dump ? ['dump', 'snapshot', 'retention', 'verify'] : ['snapshot', 'retention', 'verify'], null, $jobPayload + ['paths' => $paths]);
        $backup = BackupJob::create([
            'backup_policy_id' => $policy?->id, 'application_id' => $applicationId, 'job_id' => $job->id, 'server_id' => $server->id,
            'target_type' => $targetType, 'target_id' => $targetId, 'trigger' => $trigger, 'status' => 'running', 'started_at' => now(),
        ]);
        $job->update(['resource_type' => 'BackupJob', 'resource_id' => $backup->id]);
        $this->jobs->stage($job, $dump ? 'dump' : 'snapshot', 'running');
        $dest = $this->destination($policy, $backup->organization_id);
        $scope = $this->scopeTag($policy, $targetType, $targetId, $applicationId);
        $retention = $policy?->retention_days ?? ($applicationId ? $this->appRetentionDays() : 14);
        $payload = [
            'repository' => $dest->repository(),
            'paths' => $paths,
            'tags' => array_values(array_filter(['dockermgr', $scope, 'job-'.$backup->id])),
            'env' => (object) $dest->env(),
            'init' => true,
            // Retention per policy: snapshots older than the window are forgotten and pruned.
            'forget' => $this->forget($policy, $retention, $scope),
            'check' => ['read_data_subset' => $policy?->check_subset ?: config('backups.check_subset')],
        ];
        if ($dump) {
            $payload['dump'] = $dump;
        }
        $this->bus->dispatch($server, 'backup.run', $payload, $job);
        $policy?->update(['last_run_at' => now(), 'last_status' => 'running']);

        return $backup;
    }

    /**
     * BKM-003: daily/weekly/monthly retention when the policy sets it, otherwise the day window.
     * BKM-008: an object-locked repository is forgotten but never pruned.
     */
    private function forget(?BackupPolicy $policy, int $retention, string $scope): array
    {
        $gfs = array_filter(['keep_daily' => $policy?->keep_daily, 'keep_weekly' => $policy?->keep_weekly, 'keep_monthly' => $policy?->keep_monthly]);
        $rule = $gfs ?: ['keep_within' => $retention.'d'];

        return $rule + ['keep_last' => 1, 'tag' => $scope] + ($policy?->object_lock_days ? ['no_prune' => true] : []);
    }

    /**
     * BKM-006 options: scope (everything, files, database), mode (replace, or side_by_side
     * into a separate directory), stop_app around the restore, health_check after it.
     */
    public function restore(BackupArtifact $artifact, ?string $userId, bool $freshBackupFirst, array $options = []): RestoreJob
    {
        $source = $artifact->backupJob;
        $hasDump = $source->target_type === 'database';
        $scope = $options['scope'] ?? 'everything';
        $mode = $options['mode'] ?? 'replace';
        if (($scope === 'files' && $hasDump) || ($scope === 'database' && ! $hasDump)) {
            throw ApiException::validation(['scope' => [$hasDump ? 'This snapshot holds a database dump, not files.' : 'This snapshot holds files, not a database dump.']]);
        }
        if ($mode === 'side_by_side' && $hasDump) {
            throw ApiException::validation(['mode' => ['A database is restored in place. Download the snapshot to inspect it side by side.']]);
        }
        $app = $source->application_id ? Application::allTenants()->find($source->application_id) : null;
        $stop = ! empty($options['stop_app']) && $app && $mode === 'replace';
        $health = ! empty($options['health_check']) && $app;
        $stages = array_merge($freshBackupFirst ? ['pre_restore_backup'] : [], $stop ? ['stop_app'] : [], ['restore'], $stop ? ['start_app'] : [], $health ? ['health_check'] : [], ['complete']);
        $job = $this->jobs->create('restore', $stages, null, [], $userId);
        $restore = RestoreJob::create(['backup_artifact_id' => $artifact->id, 'job_id' => $job->id, 'status' => 'running', 'user_id' => $userId,
            'scope' => $scope, 'mode' => $mode, 'stop_app' => $stop, 'health_check' => (bool) $health]);
        $job->update(['resource_type' => 'RestoreJob', 'resource_id' => $restore->id]);

        if ($freshBackupFirst) {
            // UBAK-003: a fresh backup first so the restore can be undone.
            $this->jobs->stage($job, 'pre_restore_backup', 'running');
            $pre = $this->run($source->policy, $source->target_type, $source->target_id, 'pre_restore', ['restore_job_id' => $restore->id]);
            $restore->update(['pre_restore_backup_job_id' => $pre->id]);
        } else {
            $this->dispatchRestore($restore, $job);
        }

        return $restore;
    }

    private function dispatchRestore(RestoreJob $restore, Job $job): void
    {
        $artifact = $restore->artifact;
        $source = $artifact->backupJob;
        [$server, $paths, , $dump] = $this->target($source->target_type, $source->target_id);
        $dest = $this->destination($source->policy, $source->organization_id);
        if ($restore->stop_app) {
            $this->jobs->stage($job, 'stop_app', 'running');
            foreach ($this->appContainers($source->application_id) as $name) {
                $this->bus->dispatch($server, 'container.stop', ['container_id' => $name], $job);
            }
            $this->jobs->stage($job, 'stop_app', 'succeeded', 'App stopped for the restore');
        }
        $this->jobs->stage($job, 'restore', 'running');
        $payload = [
            'repository' => $artifact->repository ?: $dest->repository(),
            'snapshot' => $artifact->snapshot_id ?: 'latest',
            'env' => (object) $dest->env(),
        ];
        $target = '/';
        if ($restore->mode === 'side_by_side') {
            // The files land beside the live ones, under their original path.
            $target = rtrim((string) config('backups.restore_root', '/var/lib/dockermgr/restores'), '/').'/'.$restore->id;
            $restore->update(['target_path' => $target]);
        }
        $payload += $dump ? ['dump' => $dump] : ['target' => $target, 'include' => $paths];
        $this->bus->dispatch($server, 'restore.run', $payload, $job);
    }

    /** BAK-005: restore the latest (or given) snapshot into a scratch dir on the agent and validate it. */
    public function restoreTest(BackupArtifact $artifact, ?string $userId = null): Job
    {
        $source = $artifact->backupJob;
        $server = Server::findOrFail($source->server_id);
        $dest = $this->destination($source->policy, $artifact->organization_id);
        $job = $this->jobs->create('backup.test', ['restore_test'], null, ['artifact_id' => $artifact->id], $userId);
        $job->update(['resource_type' => 'BackupArtifact', 'resource_id' => $artifact->id]);
        $artifact->update(['restore_test_status' => 'running']);
        $this->jobs->stage($job, 'restore_test', 'running');
        $this->bus->dispatch($server, 'backup.test', array_filter([
            'repository' => $artifact->repository ?: $dest->repository(),
            'snapshot' => $artifact->snapshot_id ?: 'latest',
            'env' => (object) $dest->env(),
            'engine' => $artifact->dump_engine,
            'check' => ['read_data_subset' => $source->policy?->check_subset ?: config('backups.check_subset')],
            'sandbox' => $this->sandbox($artifact),
        ]), $job);

        return $job;
    }

    /**
     * BKM-009: what the restore test starts from the restored data: a fresh database of
     * the dump's engine (rows counted per table), or the app's own image on its restored
     * volume (it must still be running after 20 s). Null when neither applies.
     */
    private function sandbox(BackupArtifact $artifact): ?array
    {
        if (! config('backups.sandbox')) {
            return null;
        }
        if ($image = config('backups.sandbox_images')[$artifact->dump_engine ?? ''] ?? null) {
            return ['kind' => 'db', 'image' => $image];
        }
        $app = $artifact->application_id ? Application::allTenants()->find($artifact->application_id) : null;
        $paths = (array) (Job::find($artifact->backupJob?->job_id)?->payload['paths'] ?? []);
        if ($app?->image && $app->volume_path && count($paths) === 1) {
            return ['kind' => 'app', 'image' => $app->image, 'source' => $paths[0], 'mount' => $app->volume_path, 'seconds' => 20];
        }

        return null;
    }

    /**
     * UBAK-002: a signed, expiring download URL of the real archive, exporting it first when needed.
     *
     * @return array{status: string, url?: string, expires_at?: string, job_id?: string, bytes?: int, sha256?: string}
     */
    public function download(BackupArtifact $artifact, ?string $userId = null): array
    {
        $fresh = $artifact->export_status === 'ready' && $artifact->exported_at?->gt(now()->subHours(config('backups.export_ttl_hours')));
        if ($fresh) {
            $expires = now()->addMinutes(config('backups.download_ttl_minutes'));
            $name = 'backup-'.$artifact->created_at->format('Ymd-His').'-'.substr($artifact->id, 0, 8).'.tar.gz';
            $url = $artifact->export_disk === 's3'
                ? $this->destination($artifact->backupJob->policy, $artifact->organization_id)->presignGet($artifact->export_path, $expires, $name)
                : URL::temporarySignedRoute('backup.download', $expires, ['id' => $artifact->id]);

            return ['status' => 'ready', 'url' => $url, 'expires_at' => $expires->toIso8601ZuluString(), 'bytes' => (int) $artifact->export_bytes, 'sha256' => $artifact->export_sha256];
        }
        if ($artifact->export_status === 'exporting' && $artifact->export_job_id && ($job = Job::find($artifact->export_job_id)) && ! $job->isFinished()) {
            return ['status' => 'exporting', 'job_id' => $job->id];
        }

        return ['status' => 'exporting', 'job_id' => $this->export($artifact, $userId)->id];
    }

    public function export(BackupArtifact $artifact, ?string $userId = null): Job
    {
        $source = $artifact->backupJob;
        $server = Server::findOrFail($source->server_id);
        $dest = $this->destination($source->policy, $artifact->organization_id);
        [, $paths] = $this->target($source->target_type, $source->target_id);
        $job = $this->jobs->create('backup.export', ['export', 'upload'], null, ['artifact_id' => $artifact->id], $userId);
        $job->update(['resource_type' => 'BackupArtifact', 'resource_id' => $artifact->id]);
        $expires = now()->addHours(6);
        if ($dest->isS3()) {
            $key = trim($dest->prefix(), '/').'/exports/'.$artifact->id.'.tar.gz';
            $upload = ['url' => $dest->presignPut($key, $expires), 'method' => 'PUT'];
            $disk = 's3';
        } else {
            // Local destination: the agent streams the archive to the API's own storage.
            $key = 'backup-exports/'.$artifact->organization_id.'/'.$artifact->id.'.tar.gz';
            $relative = URL::temporarySignedRoute('agent.backup-export', $expires, ['id' => $artifact->id], absolute: false);
            $upload = ['url' => rtrim(config('backups.agent_api_url'), '/').$relative, 'method' => 'PUT'];
            $disk = 'local';
        }
        $artifact->update(['export_status' => 'exporting', 'export_disk' => $disk, 'export_path' => $key, 'export_job_id' => $job->id]);
        $this->jobs->stage($job, 'export', 'running');
        $this->bus->dispatch($server, 'backup.export', [
            'repository' => $artifact->repository ?: $dest->repository(),
            'snapshot' => $artifact->snapshot_id ?: 'latest',
            'env' => (object) $dest->env(),
            // Dumps live under the agent state dir; file backups under their first path.
            'path' => $artifact->dump_engine ? '/' : ($paths[0] ?? '/'),
            'upload' => $upload,
        ], $job);

        return $job;
    }

    /** Receives a local-destination export from the agent (signed URL route). */
    public function storeUpload(BackupArtifact $artifact, $stream, ?string $sha256): void
    {
        $disk = Storage::disk(config('backups.export_disk'));
        $path = $artifact->export_path ?: 'backup-exports/'.$artifact->organization_id.'/'.$artifact->id.'.tar.gz';
        $disk->put($path, $stream);
        $artifact->update(['export_path' => $path, 'export_disk' => 'local', 'export_bytes' => $disk->size($path), 'export_sha256' => $sha256 ?: hash_file('sha256', $disk->path($path))]);
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        match ($job->type) {
            'restore' => $this->restoreFinished($command, $job),
            'backup.test' => $this->testFinished($command, $job),
            'backup.export' => $this->exportFinished($command, $job),
            'backup.probe' => $this->probeFinished($command, $job),
            default => $this->backupFinished($command, $job),
        };
    }

    private function backupFinished(AgentCommand $command, Job $job): void
    {
        $backup = BackupJob::where('job_id', $job->id)->first();
        if (! $backup) {
            return;
        }
        $policy = $backup->policy;
        $output = $command->output ?? [];
        $hasDump = isset($command->payload['dump']);
        if ($command->status !== 'succeeded') {
            $backup->update(['status' => 'failed', 'error' => $command->message, 'finished_at' => now()]);
            $policy?->update(['last_status' => 'failed']);
            $this->jobs->fail($job, $command->message ?: 'Backup failed', $hasDump && ! isset($output['dump']) ? 'dump' : 'snapshot');
            $this->notifyFailed($backup, $command->message ?: 'Backup failed');
            if ($restoreId = $job->payload['restore_job_id'] ?? null) {
                $restore = RestoreJob::find($restoreId);
                $restore?->update(['status' => 'failed']);
                $restoreJob = $restore ? Job::find($restore->job_id) : null;
                $restoreJob && $this->jobs->fail($restoreJob, 'The safety backup before restoring failed, so nothing was restored.', 'pre_restore_backup');
            }

            return;
        }
        $summary = $output['summary'] ?? $output;
        $snapshot = $output['snapshot_id'] ?? $summary['snapshot_id'] ?? null;
        $bytes = $summary['total_bytes_processed'] ?? $summary['data_added'] ?? 0;
        $check = $output['check'] ?? null;
        $verified = $snapshot && ($check === null || ($check['ok'] ?? false));
        $retention = $policy?->retention_days ?? ($backup->application_id ? $this->appRetentionDays() : 14);
        $artifact = BackupArtifact::create([
            'backup_job_id' => $backup->id, 'backup_policy_id' => $policy?->id, 'application_id' => $backup->application_id, 'snapshot_id' => $snapshot,
            'repository' => $command->payload['repository'], 'size_mb' => round($bytes / 1048576, 2),
            'checksum' => $snapshot ? hash('sha256', $snapshot) : null,
            'verified' => $verified, 'verified_at' => $check ? now() : null,
            'verify_message' => $check ? (($check['ok'] ?? false) ? 'restic check --read-data-subset='.($check['subset'] ?? '') : ($check['message'] ?? 'check failed')) : null,
            'dump_engine' => $command->payload['dump']['engine'] ?? null,
            'encrypted' => true, 'expires_at' => now()->addDays($retention),
        ]);
        if ($hasDump) {
            $this->jobs->stage($job, 'dump', 'succeeded', round(($output['dump']['bytes'] ?? 0) / 1024, 1).' KiB '.$command->payload['dump']['engine'].' dump');
        }
        $this->jobs->stage($job, 'snapshot', 'succeeded', 'Snapshot '.($snapshot ?? 'created'));
        $this->jobs->stage($job, 'retention', isset($output['forget']) ? 'succeeded' : 'skipped', isset($output['forget']) ? 'Forgot '.($output['forget']['removed'] ?? 0).' old snapshot(s)' : null);
        if ($check && ! ($check['ok'] ?? false)) {
            $this->jobs->stage($job, 'verify', 'failed', 'Integrity check failed: '.Str::limit((string) ($check['message'] ?? ''), 200));
            $backup->update(['status' => 'failed', 'error' => 'Integrity check failed', 'finished_at' => now()]);
            $policy?->update(['last_status' => 'failed']);
            $this->notifyFailed($backup, 'The integrity check of the backup repository failed.');
            $this->jobs->fail($job, 'restic check reported errors for the repository.', 'verify', ['artifact_id' => $artifact->id]);

            return;
        }
        $this->jobs->stage($job, 'verify', $verified ? 'succeeded' : 'skipped', $check ? 'restic check passed ('.($check['subset'] ?? '').' of data read)' : 'No integrity check');
        $backup->update(['status' => 'succeeded', 'finished_at' => now()]);
        $policy?->update(['last_status' => 'succeeded']);
        if ($backup->target_type === 'database') {
            Database::whereKey($backup->target_id)->update(['last_backup_at' => now()]);
        }
        $this->jobs->succeed($job, ['backup_job_id' => $backup->id, 'artifact_id' => $artifact->id, 'snapshot_id' => $snapshot]);

        if ($restoreId = $job->payload['restore_job_id'] ?? null) {
            $restore = RestoreJob::find($restoreId);
            $restoreJob = $restore ? Job::find($restore->job_id) : null;
            if ($restoreJob) {
                $this->jobs->stage($restoreJob, 'pre_restore_backup', 'succeeded', 'Safety backup '.$artifact->id);
                $this->dispatchRestore($restore, $restoreJob);
            }
        }
    }

    private function notifyFailed(BackupJob $backup, string $error): void
    {
        app(\App\Services\EmailTemplateService::class)->notifyOrganization($backup->organization_id, 'backup_failed', [
            'target' => $this->label($backup->target_type, $backup->target_id), 'error' => $error,
            'time' => now()->toDayDateTimeString().' UTC',
        ]);
    }

    /** "application shop", "database orders", or the type alone when the record is gone. */
    private function label(?string $type, ?string $id): string
    {
        $name = match ($type) {
            'application' => Application::allTenants()->whereKey($id)->value('name'),
            'database' => Database::allTenants()->whereKey($id)->value('name'),
            'volume' => \App\Models\Volume::allTenants()->whereKey($id)->value('name'),
            default => null,
        };

        return trim(($type ?? 'backup').' '.($name ?? ''));
    }

    private function restoreFinished(AgentCommand $command, Job $job): void
    {
        $restore = RestoreJob::where('job_id', $job->id)->first();
        $ok = $command->status === 'succeeded';
        $app = $restore?->artifact?->backupJob?->application_id ? Application::allTenants()->find($restore->artifact->backupJob->application_id) : null;
        switch ($command->type) {
            case 'container.stop':
                return; // reported with the restore
            case 'restore.run':
                if (! $ok) {
                    $restore?->update(['status' => 'failed', 'message' => $command->message]);
                    // Bring a stopped app back even when the restore failed.
                    $restore?->stop_app && $app && $this->startApp($app, $command, $job);
                    $this->jobs->fail($job, $command->message ?: 'Restore failed', 'restore');

                    return;
                }
                $this->jobs->stage($job, 'restore', 'succeeded', $restore?->target_path ? 'Restored beside the live files in '.$restore->target_path : 'Restored');
                if ($restore?->stop_app && $app) {
                    $this->jobs->stage($job, 'start_app', 'running');
                    $this->startApp($app, $command, $job);

                    return;
                }
                break;
            case 'container.start':
                $this->jobs->stage($job, 'start_app', $ok ? 'succeeded' : 'failed', $ok ? 'App started' : ($command->message ?: 'The app did not start'));
                if ($restore?->status === 'failed') {
                    return;
                }
                break;
            case 'container.healthcheck':
                $this->jobs->stage($job, 'health_check', $ok ? 'succeeded' : 'failed', $ok ? 'App is healthy after the restore' : ($command->message ?: 'Health check failed'));
                $restore?->update(['status' => $ok ? 'succeeded' : 'failed', 'message' => $ok ? null : 'Restored, but the health check failed.']);
                $ok ? $this->restoreSucceeded($restore, $job) : $this->jobs->fail($job, 'Restored, but the app failed its health check.', 'health_check');

                return;
            default:
                return;
        }
        // BKM-006: a health check once the data and app are back.
        if ($restore?->health_check && $app && $app->server) {
            $this->jobs->stage($job, 'health_check', 'running');
            $this->bus->dispatch($app->server, 'container.healthcheck', array_filter([
                'container_id' => $app->containerName(), 'port' => $app->port ? (int) $app->port : null,
                'path' => $app->health_path ?: config('platform.builds.health_path'), 'timeout_s' => 60,
            ], fn ($v) => $v !== null), $job);

            return;
        }
        $restore?->update(['status' => 'succeeded']);
        $this->restoreSucceeded($restore, $job);
    }

    private function restoreSucceeded(?RestoreJob $restore, Job $job): void
    {
        $this->jobs->stage($job, 'complete', 'succeeded');
        $this->jobs->succeed($job, ['restore_job_id' => $restore?->id, 'pre_restore_backup_job_id' => $restore?->pre_restore_backup_job_id, 'target_path' => $restore?->target_path]);
        if ($restore) {
            $artifact = BackupArtifact::find($restore->backup_artifact_id);
            app(\App\Services\EmailTemplateService::class)->notifyOrganization($job->organization_id, 'restore_complete', [
                'target' => $this->label($artifact?->application_id ? 'application' : 'backup', $artifact?->application_id),
                'snapshot' => (string) ($artifact?->snapshot_id ?? '—'),
            ]);
        }
    }

    private function startApp(Application $app, AgentCommand $command, Job $job): void
    {
        foreach ($this->appContainers($app->id) as $name) {
            $this->bus->dispatch($command->server, 'container.start', ['container_id' => $name], $job);
        }
    }

    /** @return string[] the app's container names */
    private function appContainers(?string $applicationId): array
    {
        if (! $applicationId) {
            return [];
        }
        $names = \App\Models\Container::allTenants()->where('application_id', $applicationId)->pluck('name')->filter()->values()->all();
        $app = Application::allTenants()->find($applicationId);

        return $names ?: ($app ? [$app->containerName()] : []);
    }

    private function testFinished(AgentCommand $command, Job $job): void
    {
        $artifact = BackupArtifact::find($job->payload['artifact_id'] ?? null);
        $ok = $command->status === 'succeeded';
        $out = $command->output ?? [];
        $sb = (array) ($out['sandbox'] ?? []);
        $sandbox = match ($sb['kind'] ?? null) {
            'db' => sprintf('; sandbox database: %d tables, %s rows', $sb['tables'] ?? 0, number_format((int) ($sb['rows'] ?? 0))),
            'app' => ! empty($sb['running']) ? sprintf('; the app ran on the restored data for %ds', $sb['seconds'] ?? 0) : '',
            default => '',
        };
        $message = $ok ? sprintf('Restored %d file(s), %s KiB%s', $out['files'] ?? 0, round(($out['bytes'] ?? 0) / 1024, 1), isset($out['dump_valid']) ? ', dump valid' : '').$sandbox : ($command->message ?: 'Restore test failed');
        $artifact?->update(['restore_tested_at' => now(), 'restore_test_status' => $ok ? 'passed' : 'failed', 'restore_test_message' => $message]);
        $policy = $artifact?->backupJob?->policy;
        $policy?->update(['last_restore_test_at' => now(), 'last_restore_test_status' => $ok ? 'passed' : 'failed']);
        if ($ok) {
            $this->jobs->stage($job, 'restore_test', 'succeeded', $message);
            $this->jobs->succeed($job, ['artifact_id' => $artifact?->id] + $out);
        } else {
            $this->jobs->fail($job, $message, 'restore_test');
        }
    }

    private function exportFinished(AgentCommand $command, Job $job): void
    {
        $artifact = BackupArtifact::find($job->payload['artifact_id'] ?? null);
        if (! $artifact) {
            return;
        }
        if ($command->status !== 'succeeded') {
            $artifact->update(['export_status' => 'failed']);
            $this->jobs->fail($job, $command->message ?: 'Export failed', 'export');

            return;
        }
        $out = $command->output ?? [];
        if ($artifact->export_disk === 'local' && ! Storage::disk(config('backups.export_disk'))->exists($artifact->export_path)) {
            $artifact->update(['export_status' => 'failed']);
            $this->jobs->fail($job, 'The agent reported success but the archive never arrived.', 'upload');

            return;
        }
        $artifact->update(['export_status' => 'ready', 'exported_at' => now(), 'export_bytes' => $out['bytes'] ?? $artifact->export_bytes, 'export_sha256' => $out['sha256'] ?? $artifact->export_sha256]);
        $this->jobs->stage($job, 'export', 'succeeded', 'tar.gz of snapshot '.$artifact->snapshot_id);
        $this->jobs->stage($job, 'upload', 'succeeded', ($out['bytes'] ?? 0).' bytes');
        $this->jobs->succeed($job, ['artifact_id' => $artifact->id, 'bytes' => $out['bytes'] ?? null, 'sha256' => $out['sha256'] ?? null]);
    }

    private function appRetentionDays(): int
    {
        return (int) (app(BillingService::class)->plan()->limits['backup_retention_days'] ?? 14);
    }

    /** Deletes expired artifacts (the snapshots are removed by restic forget --prune on the next run). */
    public function pruneExpired(): int
    {
        $expired = BackupArtifact::allTenants()->where('expires_at', '<', now())->get();
        foreach ($expired as $artifact) {
            if ($artifact->export_disk === 'local' && $artifact->export_path) {
                Storage::disk(config('backups.export_disk'))->delete($artifact->export_path);
            }
            $artifact->delete();
        }

        return $expired->count();
    }

    /** BKM-007: reach the repository with its keys and record what it stores. */
    public function probe(BackupPolicy $policy, ?string $userId = null): Job
    {
        [$server] = $this->target($policy->target_type, $policy->target_id);
        if (! $server->isOnline()) {
            throw ApiException::conflict('The server that holds this target is offline, so the destination cannot be checked from it.');
        }
        $dest = $this->destination($policy);
        $job = $this->jobs->create('backup.probe', ['probe'], $policy, [], $userId);
        $this->bus->dispatch($server, 'backup.probe', ['repository' => $dest->repository(), 'env' => (object) $dest->env()], $job);

        return $job;
    }

    private function probeFinished(AgentCommand $command, Job $job): void
    {
        $policy = BackupPolicy::allTenants()->find($job->resource_id);
        $out = (array) $command->output;
        $result = $command->status === 'succeeded' ? $out : ['reachable' => false, 'message' => $command->message];
        $policy?->update(['last_probe' => $result, 'probed_at' => now()]);
        ($result['reachable'] ?? false) ? $this->jobs->succeed($job, $result) : $this->jobs->fail($job, (string) ($result['message'] ?? 'The destination could not be reached.'));
    }

    /** BKM-007: usage against the stated capacity, with a warning above 85 %. */
    public static function usage(BackupPolicy $policy): array
    {
        $stored = (int) ($policy->last_probe['stored_bytes'] ?? 0);
        if (! $stored) {
            $stored = (int) round(BackupArtifact::allTenants()->where('backup_policy_id', $policy->id)->sum('size_mb') * 1048576);
        }
        $pct = $policy->capacity_gb ? round($stored / ($policy->capacity_gb * 1073741824) * 100, 1) : null;

        return ['stored_bytes' => $stored, 'capacity_gb' => $policy->capacity_gb, 'percent' => $pct, 'warning' => $pct !== null && $pct >= 85];
    }

    /** BKM-001: the numbers at the top of the Backup manager. */
    public function summary(): array
    {
        $policies = BackupPolicy::all();
        $jobs = BackupJob::where('kind', 'backup')->where('created_at', '>=', now()->subDays(30))->whereIn('status', ['succeeded', 'failed'])->get(['status']);
        $tested = BackupArtifact::whereNotNull('restore_tested_at')->orderByDesc('restore_tested_at')->first();
        $next = $policies->where('enabled', true)->map(fn (BackupPolicy $p) => \Cron\CronExpression::isValidExpression($p->schedule) ? (new \Cron\CronExpression($p->schedule))->getNextRunDate(now()) : null)->filter()->min();

        return [
            'protected_targets' => $policies->where('enabled', true)->map(fn ($p) => $p->target_type.':'.$p->target_id)
                ->merge(BackupJob::whereNotNull('application_id')->distinct()->pluck('application_id')->map(fn ($id) => 'application:'.$id))->unique()->count(),
            'success_rate_30d' => $jobs->count() ? round($jobs->where('status', 'succeeded')->count() / $jobs->count() * 100, 1) : null,
            'runs_30d' => $jobs->count(),
            'stored_mb' => round((float) BackupArtifact::where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('size_mb'), 1),
            'last_restore_test' => $tested ? ['at' => $tested->restore_tested_at->toIso8601ZuluString(), 'status' => $tested->restore_test_status] : null,
            'next_run_at' => $next ? \Illuminate\Support\Carbon::instance($next)->toIso8601ZuluString() : null,
            'paused' => $policies->where('enabled', false)->count(),
        ];
    }

    /** BKM-004: run every policy that missed its last scheduled time, or whose last run failed. */
    public function runAll(string $which): array
    {
        $started = [];
        foreach (BackupPolicy::where('enabled', true)->get() as $policy) {
            $due = $which === 'due' && \Cron\CronExpression::isValidExpression($policy->schedule)
                && (! $policy->last_run_at || $policy->last_run_at->lt((new \Cron\CronExpression($policy->schedule))->getPreviousRunDate(now(), 0, true)));
            $failed = $which === 'failed' && $policy->last_status === 'failed';
            if (! $due && ! $failed) {
                continue;
            }
            try {
                $started[] = $this->run($policy, $policy->target_type, $policy->target_id, 'manual')->id;
            } catch (\Throwable $e) {
                $policy->update(['last_status' => 'failed']);
                report($e);
            }
        }

        return $started;
    }

    public function dueSchedules(): \Illuminate\Support\Collection
    {
        return BackupPolicy::allTenants()->where('enabled', true)->get()
            ->filter(fn (BackupPolicy $p) => (new \Cron\CronExpression($p->schedule))->isDue(now()));
    }

    /** BAK-005: latest verified artifact of each policy whose restore-test schedule is due. */
    public function dueRestoreTests(): \Illuminate\Support\Collection
    {
        return BackupPolicy::allTenants()->where('enabled', true)->whereNotNull('restore_test_schedule')->get()
            ->filter(fn (BackupPolicy $p) => \Cron\CronExpression::isValidExpression($p->restore_test_schedule) && (new \Cron\CronExpression($p->restore_test_schedule))->isDue(now()))
            ->map(fn (BackupPolicy $p) => BackupArtifact::allTenants()->where('backup_policy_id', $p->id)->whereNotNull('snapshot_id')->latest()->first())
            ->filter()
            ->concat($this->dueAppRestoreTests())->values();
    }

    /** Customer app backups (no policy) are tested on backups.restore_test_schedule. */
    private function dueAppRestoreTests(): \Illuminate\Support\Collection
    {
        $cron = config('backups.restore_test_schedule');
        if (! $cron || ! (new \Cron\CronExpression($cron))->isDue(now())) {
            return collect();
        }

        return BackupArtifact::allTenants()->whereNull('backup_policy_id')->whereNotNull('application_id')->whereNotNull('snapshot_id')
            ->latest()->get()->unique('application_id')->values();
    }
}
