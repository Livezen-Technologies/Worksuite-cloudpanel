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

/** AUT-001/002, UCRON: cron, webhook and manual jobs with last result and next run. */
class ScheduledJobService implements \App\Contracts\HandlesCommandResults
{
    public const ACTIONS = ['backup_policy', 'container_restart', 'image_prune', 'container_prune', 'webhook', 'app_command', 'url'];

    public function __construct(private CommandBus $bus, private JobService $jobs, private BackupService $backups) {}

    public function nextRun(ScheduledJob $job): ?\DateTimeInterface
    {
        return ($job->trigger ?? 'cron') === 'cron' && $job->cron && $job->enabled !== false ? (new CronExpression($job->cron))->getNextRunDate(now()) : null;
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

    public function run(ScheduledJob $scheduled, string $source = 'schedule'): Job
    {
        $params = $scheduled->params ?? [];
        $job = $this->jobs->create('scheduled.'.$scheduled->action, ['execute'], $scheduled);
        if (in_array($scheduled->action, ['app_command', 'url'], true)) {
            return $this->runCustomer($scheduled, $job, $source);
        }
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

    /**
     * UCRON-2: a command in the app's container (non-root, with its timeout) or a GET to a URL.
     * Each run is recorded with exit code and the last 64 KB of output (CRON-3).
     */
    private function runCustomer(ScheduledJob $scheduled, Job $job, string $source): Job
    {
        $run = \App\Models\ScheduledJobRun::allTenants()->create([
            'organization_id' => $scheduled->organization_id, 'scheduled_job_id' => $scheduled->id, 'status' => 'running',
            'source' => $source, 'job_id' => $job->id, 'started_at' => now(),
        ]);
        $scheduled->update(['last_run_at' => now(), 'last_status' => 'running', 'next_run_at' => $this->nextRun($scheduled)]);
        if ($scheduled->action === 'url') {
            $started = microtime(true);
            try {
                $res = Http::timeout(min(60, $scheduled->timeout ?: 30))->withHeaders(['User-Agent' => 'WorkSuite-Cron/1.0'])->get($scheduled->params['url']);
                $this->finishRun($scheduled, $run, $job, $res->successful() ? 0 : $res->status(), 'HTTP '.$res->status()."\n".mb_strcut($res->body(), 0, 4096), (int) ((microtime(true) - $started) * 1000));
            } catch (\Throwable $e) {
                $this->finishRun($scheduled, $run, $job, -1, $e->getMessage(), (int) ((microtime(true) - $started) * 1000));
            }

            return $job->fresh();
        }
        $app = \App\Models\Application::allTenants()->find($scheduled->application_id);
        $container = $app ? Container::allTenants()->where('application_id', $app->id)->where('status', 'running')->first() : null;
        $server = $app?->server_id ? Server::allTenants()->find($app->server_id) : null;
        if (! $app || ! $server || ! $server->isOnline()) {
            $this->finishRun($scheduled, $run, $job, -1, 'The app is not running on an online server.', 0);

            return $job->fresh();
        }
        $this->bus->dispatch($server, 'cron.exec', [
            'container' => $container?->name ?? $app->containerName(), 'command' => (string) $scheduled->params['command'],
            'user' => self::execUser($app), 'timeout' => (int) ($scheduled->timeout ?: 600),
        ], $job);

        return $job;
    }

    /** CRON-8: the app's own non-root user; web images run as www-data. */
    public static function execUser(\App\Models\Application $app): string
    {
        return preg_match('/^(wordpress|php|drupal|joomla|nextcloud)[:@]/', (string) $app->image) ? '33:33' : '1000:1000';
    }

    public function onCommandFinished(\App\Models\AgentCommand $command, Job $job): void
    {
        $run = \App\Models\ScheduledJobRun::allTenants()->where('job_id', $job->id)->first();
        $scheduled = $run ? ScheduledJob::allTenants()->find($run->scheduled_job_id) : null;
        if (! $run || ! $scheduled) {
            $this->jobs->fail($job, 'The scheduled job no longer exists.');

            return;
        }
        $out = (array) $command->output;
        $command->status === 'succeeded'
            ? $this->finishRun($scheduled, $run, $job, (int) ($out['exit_code'] ?? 0), (string) ($out['output'] ?? ''), (int) ($out['duration_ms'] ?? 0), (bool) ($out['timed_out'] ?? false))
            : $this->finishRun($scheduled, $run, $job, -1, (string) $command->message, 0);
    }

    private function finishRun(ScheduledJob $scheduled, \App\Models\ScheduledJobRun $run, Job $job, int $exit, string $output, int $ms, bool $timedOut = false): void
    {
        $ok = $exit === 0;
        $run->update(['status' => $ok ? 'succeeded' : 'failed', 'exit_code' => $exit, 'output' => mb_strcut($output, max(0, strlen($output) - 65536)), 'duration_ms' => $ms, 'finished_at' => now()]);
        $scheduled->update(['last_status' => $ok ? 'succeeded' : 'failed', 'last_result' => $timedOut ? 'Timed out' : ($ok ? 'Exit 0' : "Exit {$exit}")]);
        $ok ? $this->jobs->succeed($job, ['exit_code' => $exit]) : $this->jobs->fail($job, $timedOut ? 'Timed out' : "Exit {$exit}");
        // CRON-3: keep 30 days of history.
        \App\Models\ScheduledJobRun::allTenants()->where('scheduled_job_id', $scheduled->id)->where('started_at', '<', now()->subDays(30))->delete();
    }
}
