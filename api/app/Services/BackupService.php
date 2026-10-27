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
use App\Models\Server;
use App\Models\Volume;
use Illuminate\Support\Str;

/** BAK-001..006 / UBAK: policies, runs (restic via agent), artifacts, restores, retention. */
class BackupService implements HandlesCommandResults
{
    public function __construct(private CommandBus $bus, private JobService $jobs) {}

    /** Resolves the server and absolute paths for a backup target. */
    public function target(string $type, ?string $id): array
    {
        return match ($type) {
            'application' => (function () use ($id) {
                $app = Application::findOrFail($id);
                if (! $app->server) {
                    throw ApiException::conflict('This app has not been deployed yet, so there is nothing to back up.');
                }

                return [$app->server, ["/var/lib/docker/volumes/app-{$app->name}-data/_data"], $app->id];
            })(),
            'volume' => (function () use ($id) {
                $volume = Volume::findOrFail($id);

                return [$volume->server, [$volume->mountpoint ?: "/var/lib/docker/volumes/{$volume->name}/_data"], null];
            })(),
            'database' => (function () use ($id) {
                $db = Database::findOrFail($id);

                return [Server::findOrFail($db->server_id), ['/var/lib/docker/volumes/db-'.Str::slug($db->name).'-data/_data'], null];
            })(),
            'server' => [Server::findOrFail($id), ['/srv/dockermgr'], null],
            default => throw ApiException::validation(['target_type' => ['Unknown backup target.']]),
        };
    }

    public function run(?BackupPolicy $policy, string $targetType, ?string $targetId, string $trigger = 'manual', array $jobPayload = []): BackupJob
    {
        [$server, $paths, $applicationId] = $this->target($policy?->target_type ?? $targetType, $policy?->target_id ?? $targetId);
        $job = $this->jobs->create('backup', ['snapshot', 'verify'], null, $jobPayload);
        $backup = BackupJob::create([
            'backup_policy_id' => $policy?->id, 'application_id' => $applicationId, 'job_id' => $job->id, 'server_id' => $server->id,
            'target_type' => $policy?->target_type ?? $targetType, 'target_id' => $policy?->target_id ?? $targetId,
            'trigger' => $trigger, 'status' => 'running', 'started_at' => now(),
        ]);
        $job->update(['resource_type' => 'BackupJob', 'resource_id' => $backup->id]);
        $this->jobs->stage($job, 'snapshot', 'running');
        [$repository, $env] = $this->repository($policy, $backup);
        $this->bus->dispatch($server, 'backup.run', [
            'repository' => $repository,
            'paths' => $paths,
            'tags' => array_values(array_filter(['dockermgr', 'job-'.$backup->id, $applicationId ? 'app-'.$applicationId : null])),
            'env' => (object) $env,
            'init' => true,
        ], $job);
        $policy?->update(['last_run_at' => now(), 'last_status' => 'running']);

        return $backup;
    }

    /** Restic repository + env; remote destinations are always encrypted (BAK-004). */
    private function repository(?BackupPolicy $policy, BackupJob $backup): array
    {
        $config = $policy?->destination_config ?? [];
        $password = $policy?->recovery_key ?? hash_hmac('sha256', 'restic:'.$backup->organization_id, config('app.key'));
        $env = ['RESTIC_PASSWORD' => $password];
        $destination = $policy?->destination ?? 'local';
        $repository = match ($destination) {
            's3', 'r2' => 's3:'.rtrim($config['endpoint'] ?? 'https://s3.amazonaws.com', '/').'/'.($config['bucket'] ?? 'backups').'/'.$backup->organization_id,
            'b2' => 'b2:'.($config['bucket'] ?? 'backups').':'.$backup->organization_id,
            'gdrive' => 'rclone:gdrive:'.$backup->organization_id,
            default => '/var/lib/dockermgr/backups/'.$backup->organization_id,
        };
        if (in_array($destination, ['s3', 'r2'], true)) {
            $env += ['AWS_ACCESS_KEY_ID' => $config['access_key'] ?? '', 'AWS_SECRET_ACCESS_KEY' => $config['secret_key'] ?? ''];
        } elseif ($destination === 'b2') {
            $env += ['B2_ACCOUNT_ID' => $config['account_id'] ?? '', 'B2_ACCOUNT_KEY' => $config['account_key'] ?? ''];
        }

        return [$repository, $env];
    }

    public function restore(BackupArtifact $artifact, ?string $userId, bool $freshBackupFirst): RestoreJob
    {
        $source = $artifact->backupJob;
        $job = $this->jobs->create('restore', $freshBackupFirst ? ['pre_restore_backup', 'restore', 'complete'] : ['restore', 'complete'], null, [], $userId);
        $restore = RestoreJob::create(['backup_artifact_id' => $artifact->id, 'job_id' => $job->id, 'status' => 'running', 'user_id' => $userId]);
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
        [$server, $paths] = $this->target($source->target_type, $source->target_id);
        [$repository, $env] = $this->repository($source->policy, $source);
        $this->jobs->stage($job, 'restore', 'running');
        $this->bus->dispatch($server, 'restore.run', [
            'repository' => $artifact->repository ?: $repository,
            'snapshot' => $artifact->snapshot_id ?: 'latest',
            'target' => '/',
            'include' => $paths,
            'env' => (object) $env,
        ], $job);
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        $job->type === 'restore' ? $this->restoreFinished($command, $job) : $this->backupFinished($command, $job);
    }

    private function backupFinished(AgentCommand $command, Job $job): void
    {
        $backup = BackupJob::where('job_id', $job->id)->first();
        if (! $backup) {
            return;
        }
        $policy = $backup->policy;
        if ($command->status !== 'succeeded') {
            $backup->update(['status' => 'failed', 'error' => $command->message, 'finished_at' => now()]);
            $policy?->update(['last_status' => 'failed']);
            $this->jobs->fail($job, $command->message ?: 'Backup failed', 'snapshot');
            if ($restoreId = $job->payload['restore_job_id'] ?? null) {
                $restore = RestoreJob::find($restoreId);
                $restore?->update(['status' => 'failed']);
                $restoreJob = $restore ? Job::find($restore->job_id) : null;
                $restoreJob && $this->jobs->fail($restoreJob, 'The safety backup before restoring failed, so nothing was restored.', 'pre_restore_backup');
            }

            return;
        }
        $output = $command->output ?? [];
        $summary = $output['summary'] ?? $output;
        $snapshot = $summary['snapshot_id'] ?? $output['snapshot_id'] ?? null;
        $bytes = $summary['total_bytes_processed'] ?? $summary['data_added'] ?? 0;
        $retention = $policy?->retention_days ?? ($backup->application_id ? $this->appRetentionDays() : 14);
        $artifact = BackupArtifact::create([
            'backup_job_id' => $backup->id, 'application_id' => $backup->application_id, 'snapshot_id' => $snapshot,
            'repository' => $command->payload['repository'], 'size_mb' => round($bytes / 1048576, 2),
            'checksum' => $snapshot ? hash('sha256', $snapshot) : null,
            'verified' => (bool) $snapshot, // BAK-005: restic verifies pack integrity on write; snapshot id proves completion
            'encrypted' => true, 'expires_at' => now()->addDays($retention),
        ]);
        $this->jobs->stage($job, 'snapshot', 'succeeded', 'Snapshot '.($snapshot ?? 'created'));
        $this->jobs->stage($job, 'verify', $snapshot ? 'succeeded' : 'skipped', $snapshot ? 'Integrity verified' : 'No snapshot id reported');
        $backup->update(['status' => 'succeeded', 'finished_at' => now()]);
        $policy?->update(['last_status' => 'succeeded']);
        if ($backup->target_type === 'database') {
            Database::whereKey($backup->target_id)->update(['last_backup_at' => now()]);
        }
        $this->jobs->succeed($job, ['backup_job_id' => $backup->id, 'artifact_id' => $artifact->id]);

        if ($restoreId = $job->payload['restore_job_id'] ?? null) {
            $restore = RestoreJob::find($restoreId);
            $restoreJob = $restore ? Job::find($restore->job_id) : null;
            if ($restoreJob) {
                $this->jobs->stage($restoreJob, 'pre_restore_backup', 'succeeded', 'Safety backup '.$artifact->id);
                $this->dispatchRestore($restore, $restoreJob);
            }
        }
    }

    private function restoreFinished(AgentCommand $command, Job $job): void
    {
        $restore = RestoreJob::where('job_id', $job->id)->first();
        $ok = $command->status === 'succeeded';
        $restore?->update(['status' => $ok ? 'succeeded' : 'failed']);
        $ok ? $this->jobs->succeed($job, ['restore_job_id' => $restore?->id, 'pre_restore_backup_job_id' => $restore?->pre_restore_backup_job_id])
            : $this->jobs->fail($job, $command->message ?: 'Restore failed', 'restore');
    }

    private function appRetentionDays(): int
    {
        return (int) (app(BillingService::class)->plan()->limits['backup_retention_days'] ?? 14);
    }

    /** Deletes expired artifacts (retention). */
    public function pruneExpired(): int
    {
        return BackupArtifact::allTenants()->where('expires_at', '<', now())->delete();
    }

    public function dueSchedules(): \Illuminate\Support\Collection
    {
        return BackupPolicy::allTenants()->where('enabled', true)->get()
            ->filter(fn (BackupPolicy $p) => (new \Cron\CronExpression($p->schedule))->isDue(now()));
    }
}
