<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Models\EnvVar;
use App\Models\Project;
use App\Models\RestoreJob;

/**
 * Project-level backup and restore. A project backup is a parent BackupJob (target_type "project")
 * whose children are the ordinary application backups (volumes, and database dumps where the app has
 * them), plus a snapshot of each application's configuration: image, port, size and limits, strategy,
 * health check, volume, domains and env variable names (never values). Container images are not
 * copied; they come back from their registry. The parent's status follows its children.
 */
class ProjectBackupService
{
    public function __construct(private BackupService $backups) {}

    /** @param  array<int,string>|null  $appIds  limit to these applications */
    public function backup(Project $project, string $trigger = 'manual', ?BackupPolicy $policy = null, ?array $appIds = null): BackupJob
    {
        $apps = $this->apps($project, $appIds);
        // The project's managed databases are dumped too (whole-project runs only), and two apps that share
        // the same volumes (an enterprise web + worker) are backed up once.
        $databases = $appIds ? collect() : $this->databases($project);
        $seen = [];
        $apps = $apps->filter(function (Application $a) use (&$seen) {
            $key = implode('|', BackupService::appPaths($a));

            return isset($seen[$key]) ? false : ($seen[$key] = true);
        })->values();
        if ($apps->isEmpty()) {
            throw ApiException::conflict('This project has no deployed applications to back up yet.');
        }
        app(AuditLogger::class)->log('backup.project', 'projects', $project->id, ['trigger' => $trigger, 'applications' => $apps->count()], $project->organization_id);
        $parent = BackupJob::create([
            'organization_id' => $project->organization_id, 'backup_policy_id' => $policy?->id,
            'target_type' => 'project', 'target_id' => $project->id, 'project_id' => $project->id,
            'trigger' => $trigger, 'status' => 'running', 'started_at' => now(), 'metadata' => $this->snapshot($project, $apps, $databases),
        ]);
        $log = [];
        foreach ($apps as $app) {
            try {
                $child = $this->backups->run(null, 'application', $app->id, $trigger, ['project_backup_id' => $parent->id]);
                $child->forceFill(['parent_id' => $parent->id, 'project_id' => $project->id])->saveQuietly();
                $log[] = now()->toIso8601ZuluString()." {$app->name}: backup started";
            } catch (\Throwable $e) {
                $log[] = now()->toIso8601ZuluString()." {$app->name}: could not start: ".$e->getMessage();
            }
        }
        foreach ($databases as $db) {
            try {
                $child = $this->backups->run(null, 'database', $db->id, $trigger, ['project_backup_id' => $parent->id]);
                $child->forceFill(['parent_id' => $parent->id, 'project_id' => $project->id])->saveQuietly();
                $log[] = now()->toIso8601ZuluString()." database {$db->name}: dump started";
            } catch (\Throwable $e) {
                $log[] = now()->toIso8601ZuluString()." database {$db->name}: could not start: ".$e->getMessage();
            }
        }
        $parent->forceFill(['log' => implode("\n", $log)])->saveQuietly();
        $policy?->update(['last_run_at' => now(), 'last_status' => 'running']);
        $this->rollup($parent->id);

        return $parent->fresh();
    }

    /** Settles a project backup from its children: running until all finish, then succeeded, partial or failed. */
    public function rollup(string $parentId): void
    {
        $parent = BackupJob::allTenants()->find($parentId);
        if (! $parent) {
            return;
        }
        $children = BackupJob::allTenants()->where('parent_id', $parentId)->get();
        $expected = count($parent->metadata['applications'] ?? []) + count($parent->metadata['databases'] ?? []);
        $done = $children->whereIn('status', ['succeeded', 'failed']);
        if ($children->isNotEmpty() && $done->count() < $children->count()) {
            return; // still running
        }
        $failed = $children->where('status', 'failed');
        $missing = max(0, $expected - $children->count());
        $status = match (true) {
            $children->isEmpty() => 'failed',
            $failed->isEmpty() && $missing === 0 => 'succeeded',
            $failed->count() + $missing >= $expected => 'failed',
            default => 'partial',
        };
        $names = collect($parent->metadata['applications'] ?? [])->pluck('name', 'id')->merge(collect($parent->metadata['databases'] ?? [])->pluck('name', 'id'));
        $error = $status === 'succeeded' ? null : trim(($failed->isNotEmpty() ? 'Failed: '.$failed->map(fn ($c) => $names[$c->application_id ?? $c->target_id] ?? $c->target_id)->implode(', ').'. ' : '').($missing ? "{$missing} application(s) could not start." : ''));
        $parent->forceFill(['status' => $status, 'error' => $error ?: null, 'finished_at' => $parent->finished_at ?? now()])->saveQuietly();
        if ($parent->backup_policy_id) {
            BackupPolicy::allTenants()->whereKey($parent->backup_policy_id)->update(['last_status' => $status === 'succeeded' ? 'succeeded' : 'failed']);
        }
    }

    /**
     * Restores a project backup. scope: project (every app), applications (the given ones), database
     * (dumps only) or volumes (files only). mode: replace, or separate (into a separate location beside
     * the live data). A fresh recovery point is always taken first, per application.
     *
     * @return array{restored: array<int,array>, skipped: array<int,string>}
     */
    public function restore(BackupJob $parent, ?string $userId, string $scope = 'project', ?array $appIds = null, string $mode = 'replace'): array
    {
        if ($parent->target_type !== 'project') {
            throw ApiException::validation(['id' => ['This is not a project backup.']]);
        }
        if (! in_array($parent->status, ['succeeded', 'partial'], true)) {
            throw ApiException::conflict('Only a finished project backup can be restored.');
        }
        $restored = [];
        $skipped = [];
        foreach (BackupJob::allTenants()->where('parent_id', $parent->id)->with('artifacts')->get() as $child) {
            $name = $child->target_type === 'database'
                ? 'database '.(collect($parent->metadata['databases'] ?? [])->firstWhere('id', $child->target_id)['name'] ?? $child->target_id)
                : (collect($parent->metadata['applications'] ?? [])->firstWhere('id', $child->application_id)['name'] ?? $child->target_id);
            if ($scope === 'applications' && ! in_array($child->application_id, (array) $appIds, true)) {
                continue;
            }
            $artifact = $child->artifacts->first();
            if ($child->status !== 'succeeded' || ! $artifact) {
                $skipped[] = "{$name}: no successful backup in this run";
                continue;
            }
            $isDump = (bool) $artifact->dump_engine || $child->target_type === 'database';
            if (($scope === 'database' && ! $isDump) || ($scope === 'volumes' && $isDump)) {
                continue;
            }
            $childMode = $mode === 'separate' && ! $isDump ? 'side_by_side' : 'replace';
            if ($mode === 'separate' && $isDump) {
                $skipped[] = "{$name}: a database is restored in place, so it was left out of a separate restore";
                continue;
            }
            $r = $this->backups->restore($artifact, $userId, true, ['scope' => 'everything', 'mode' => $childMode, 'health_check' => true]);
            $r->forceFill(['project_backup_job_id' => $parent->id])->saveQuietly();
            $restored[] = ['application' => $name, 'restore_job_id' => $r->id, 'job_id' => $r->job_id];
        }
        if (! $restored) {
            throw ApiException::conflict('Nothing in this backup matches what you chose to restore.'.($skipped ? ' '.implode('; ', $skipped).'.' : ''));
        }
        app(AuditLogger::class)->log('backup.project_restore', 'backup_jobs', $parent->id, ['scope' => $scope, 'mode' => $mode, 'restores' => count($restored), 'skipped' => $skipped], $parent->organization_id, $userId);

        return compact('restored', 'skipped');
    }

    /** Everything the details screen needs. */
    public function details(BackupJob $parent): array
    {
        $children = BackupJob::allTenants()->where('parent_id', $parent->id)->with('artifacts')->get();
        $artifactIds = $children->flatMap->artifacts->pluck('id');
        $apps = collect($parent->metadata['applications'] ?? [])->keyBy('id');
        $dest = $parent->backup_policy_id ? BackupPolicy::allTenants()->whereKey($parent->backup_policy_id)->value('destination') : 'local';

        return [
            'id' => $parent->id, 'project_id' => $parent->project_id, 'project' => $parent->metadata['project']['name'] ?? null,
            'type' => $parent->trigger === 'schedule' ? 'scheduled' : $parent->trigger, 'status' => $parent->status, 'error' => $parent->error,
            'created_at' => $parent->created_at, 'started_at' => $parent->started_at, 'finished_at' => $parent->finished_at,
            'destination' => $dest ?: 'local',
            'size_mb' => round((float) $children->flatMap->artifacts->sum('size_mb'), 2),
            'verified' => $children->flatMap->artifacts->isNotEmpty() && $children->flatMap->artifacts->every(fn ($a) => $a->verified),
            'log' => $parent->log,
            'applications' => $children->map(fn ($c) => [
                'id' => $c->application_id ?? $c->target_id, 'target_type' => $c->target_type, 'status' => $c->status, 'error' => $c->error,
                'name' => $c->target_type === 'database' ? 'database '.(collect($parent->metadata['databases'] ?? [])->firstWhere('id', $c->target_id)['name'] ?? $c->target_id) : ($apps[$c->application_id]['name'] ?? $c->target_id),
                'artifacts' => $c->artifacts->map(fn ($a) => ['id' => $a->id, 'size_mb' => $a->size_mb, 'kind' => $a->dump_engine ? 'database' : 'volumes', 'verified' => $a->verified, 'verify_message' => $a->verify_message])->values(),
            ])->values(),
            'configuration' => $parent->metadata,
            'restores' => RestoreJob::allTenants()->where(fn ($q) => $q->where('project_backup_job_id', $parent->id)->orWhereIn('backup_artifact_id', $artifactIds))
                ->latest()->get()->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'mode' => $r->mode, 'created_at' => $r->created_at, 'user_id' => $r->user_id,
                    'application' => $apps[BackupArtifact::allTenants()->whereKey($r->backup_artifact_id)->value('application_id')]['name'] ?? null])->values(),
        ];
    }

    private function apps(Project $project, ?array $appIds)
    {
        return Application::allTenants()->where('project_id', $project->id)->whereNotNull('server_id')
            ->when($appIds, fn ($q) => $q->whereIn('id', $appIds))->orderBy('name')->get();
    }

    /** The project's managed SQL / document databases (redis is a cache and is not dumped). */
    private function databases(Project $project)
    {
        return \App\Models\Database::allTenants()->where('project_id', $project->id)->whereNotNull('server_id')
            ->whereIn('engine', (array) config('backups.project_dump_engines', ['postgres', 'mysql', 'mariadb', 'mongodb']))->orderBy('name')->get();
    }

    /** Configuration needed to rebuild the apps; secrets and env values are never stored. */
    private function snapshot(Project $project, $apps, $databases = null): array
    {
        return [
            'databases' => collect($databases)->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'engine' => $d->engine, 'version' => $d->version])->values()->all(),
            'project' => ['id' => $project->id, 'name' => $project->name],
            'taken_at' => now()->toIso8601ZuluString(),
            'applications' => $apps->map(fn (Application $a) => [
                'id' => $a->id, 'name' => $a->name, 'source_type' => $a->source_type, 'image' => $a->image, 'port' => $a->port,
                'environment' => $a->environment, 'size' => $a->size, 'cpu_limit' => $a->cpu_limit, 'memory_limit_mb' => $a->memory_limit_mb,
                'replicas' => $a->replicas, 'strategy' => $a->strategy, 'health_path' => $a->health_path, 'volume_path' => $a->volume_path,
                'server_id' => $a->server_id, 'domains' => $a->domains()->pluck('name')->values(),
                'env_keys' => EnvVar::allTenants()->where('application_id', $a->id)->pluck('key')->values(),
            ])->values()->all(),
        ];
    }
}
