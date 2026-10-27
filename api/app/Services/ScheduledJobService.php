<?php

namespace App\Services;

use App\Models\BackupPolicy;
use App\Models\Container;
use App\Models\Job;
use App\Models\ScheduledJob;
use App\Models\Server;
use App\Support\Tenant;
use Cron\CronExpression;
use Illuminate\Support\Facades\Http;

/** AUT-001/002: cron, webhook and manual jobs with last result and next run. */
class ScheduledJobService
{
    public const ACTIONS = ['backup_policy', 'container_restart', 'image_prune', 'container_prune', 'webhook'];

    public function __construct(private CommandBus $bus, private JobService $jobs, private BackupService $backups) {}

    public function nextRun(ScheduledJob $job): ?\DateTimeInterface
    {
        return $job->trigger === 'cron' && $job->cron && $job->enabled ? (new CronExpression($job->cron))->getNextRunDate(now()) : null;
    }

    public function refreshNextRun(ScheduledJob $job): void
    {
        $job->update(['next_run_at' => $this->nextRun($job)]);
    }

    public function runDue(): int
    {
        $count = 0;
        foreach (ScheduledJob::allTenants()->where('enabled', true)->where('trigger', 'cron')->whereNotNull('cron')->get() as $job) {
            if ((new CronExpression($job->cron))->isDue(now())) {
                app(Tenant::class)->as($job->organization_id, fn () => $this->run($job));
                $count++;
            }
        }

        return $count;
    }

    public function run(ScheduledJob $scheduled): Job
    {
        $params = $scheduled->params ?? [];
        $job = $this->jobs->create('scheduled.'.$scheduled->action, ['execute'], $scheduled);
        try {
            $message = match ($scheduled->action) {
                'backup_policy' => (function () use ($params, $job) {
                    $policy = BackupPolicy::findOrFail($params['policy_id'] ?? null);
                    $backup = $this->backups->run($policy, $policy->target_type, $policy->target_id, 'schedule');
                    $this->jobs->succeed($job, ['backup_job_id' => $backup->id]);

                    return 'Backup started ('.$backup->id.')';
                })(),
                'container_restart' => (function () use ($params, $job) {
                    $container = Container::findOrFail($params['container_id'] ?? null);
                    $this->bus->dispatch($container->server, 'container.restart', ['container_id' => $container->dockerRef()], $job);

                    return 'Restart requested for '.$container->name;
                })(),
                'image_prune', 'container_prune' => (function () use ($scheduled, $params, $job) {
                    $type = $scheduled->action === 'image_prune' ? 'image.prune' : 'container.prune';
                    $servers = Server::query()->when($params['server_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get()->filter->isOnline();
                    foreach ($servers as $server) {
                        $this->bus->dispatch($server, $type, $type === 'image.prune' ? ['all' => (bool) ($params['all'] ?? false)] : ['labels' => (object) []], $job);
                    }
                    if ($servers->isEmpty()) {
                        $this->jobs->succeed($job, ['message' => 'No online servers.']);
                    }

                    return 'Prune requested on '.$servers->count().' servers';
                })(),
                'webhook' => (function () use ($params, $job) {
                    $response = Http::timeout(15)->post($params['url'], $params['body'] ?? []);
                    $response->successful() ? $this->jobs->succeed($job, ['status' => $response->status()]) : $this->jobs->fail($job, 'HTTP '.$response->status());

                    return 'Webhook returned '.$response->status();
                })(),
            };
            $scheduled->update(['last_run_at' => now(), 'last_status' => 'succeeded', 'last_result' => $message, 'next_run_at' => $this->nextRun($scheduled)]);
        } catch (\Throwable $e) {
            $this->jobs->fail($job, $e->getMessage());
            $scheduled->update(['last_run_at' => now(), 'last_status' => 'failed', 'last_result' => $e->getMessage(), 'next_run_at' => $this->nextRun($scheduled)]);
        }

        return $job->fresh();
    }
}
