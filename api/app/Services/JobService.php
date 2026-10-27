<?php

namespace App\Services;

use App\Models\Job;
use App\Models\JobAttempt;
use Illuminate\Database\Eloquent\Model;

/** Job model lifecycle: stages, progress, terminal states. */
class JobService
{
    public function create(string $type, array $stages = ['execute'], ?Model $resource = null, array $payload = [], ?string $userId = null, ?string $organizationId = null): Job
    {
        $attributes = [
            'type' => $type,
            'status' => 'queued',
            'progress' => 0,
            'stages' => array_map(fn ($name) => ['name' => $name, 'status' => 'pending', 'started_at' => null, 'finished_at' => null, 'message' => null], $stages),
            'payload' => $payload,
            'resource_type' => $resource ? class_basename($resource) : null,
            'resource_id' => $resource?->getKey(),
            'user_id' => $userId ?? auth()->id(),
        ];
        if ($organizationId ?? $resource?->organization_id) {
            $attributes['organization_id'] = $organizationId ?? $resource->organization_id;
        }

        return Job::create($attributes);
    }

    public function stage(Job $job, string $name, string $status, ?string $message = null): Job
    {
        $stages = $job->stages ?? [];
        $now = now()->toIso8601ZuluString();
        $found = false;
        foreach ($stages as &$stage) {
            if ($stage['name'] === $name) {
                $found = true;
                if ($status === 'running' && ! $stage['started_at']) {
                    $stage['started_at'] = $now;
                }
                if (in_array($status, ['succeeded', 'failed', 'skipped', 'cancelled'], true)) {
                    $stage['started_at'] ??= $now;
                    $stage['finished_at'] = $now;
                }
                $stage['status'] = $status;
                if ($message !== null) {
                    $stage['message'] = $message;
                }
            }
        }
        unset($stage);
        if (! $found) {
            $stages[] = ['name' => $name, 'status' => $status, 'started_at' => $now, 'finished_at' => in_array($status, ['running', 'pending'], true) ? null : $now, 'message' => $message];
        }
        $done = count(array_filter($stages, fn ($s) => in_array($s['status'], ['succeeded', 'skipped'], true)));
        $job->stages = $stages;
        $job->progress = $stages ? (int) floor($done / count($stages) * 100) : 0;
        if ($job->status === 'queued' && $status === 'running') {
            $job->status = 'running';
            $job->started_at ??= now();
        }
        $job->save();

        return $job;
    }

    public function start(Job $job): Job
    {
        if ($job->status === 'queued') {
            $job->update(['status' => 'running', 'started_at' => now()]);
            JobAttempt::create(['organization_id' => $job->organization_id, 'job_id' => $job->id, 'attempt' => $job->attempts()->count() + 1, 'status' => 'running']);
        }

        return $job;
    }

    public function progress(Job $job, int $progress): Job
    {
        $job->update(['progress' => max(0, min(100, $progress)), 'status' => $job->status === 'queued' ? 'running' : $job->status]);

        return $job;
    }

    public function succeed(Job $job, array $result = []): Job
    {
        $stages = array_map(function ($s) {
            if (in_array($s['status'], ['pending', 'running'], true)) {
                $s['status'] = $s['status'] === 'pending' ? 'skipped' : 'succeeded';
                $s['finished_at'] = now()->toIso8601ZuluString();
            }

            return $s;
        }, $job->stages ?? []);
        $job->update(['status' => 'succeeded', 'progress' => 100, 'stages' => $stages, 'result' => $result ?: $job->result, 'finished_at' => now(), 'started_at' => $job->started_at ?? now()]);
        $this->closeAttempt($job, 'succeeded');

        return $job;
    }

    public function fail(Job $job, string $error, ?string $stage = null, array $result = []): Job
    {
        if ($stage) {
            $this->stage($job, $stage, 'failed', $error);
        }
        $stages = array_map(function ($s) {
            if ($s['status'] === 'pending') {
                $s['status'] = 'cancelled';
            } elseif ($s['status'] === 'running') {
                $s['status'] = 'failed';
                $s['finished_at'] = now()->toIso8601ZuluString();
            }

            return $s;
        }, $job->stages ?? []);
        $job->update(['status' => 'failed', 'error' => $error, 'stages' => $stages, 'result' => $result ?: $job->result, 'finished_at' => now()]);
        $this->closeAttempt($job, 'failed', $error);

        return $job;
    }

    private function closeAttempt(Job $job, string $status, ?string $message = null): void
    {
        JobAttempt::where('job_id', $job->id)->where('status', 'running')->update(['status' => $status, 'message' => $message, 'updated_at' => now()]);
    }
}
