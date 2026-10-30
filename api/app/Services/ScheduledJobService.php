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

    /** Actions that are cron-engine jobs (CRON-1): a command somewhere, or a URL. */
    public const ENGINE = ['app_command', 'url', 'server_command'];

    public const MODES = ['container', 'oneoff', 'host', 'http'];

    /** Consecutive failures that raise a Monitoring alert (CRON-5). */
    public const FAILURE_ALERT_STREAK = 3;

    public function nextRun(ScheduledJob $job): ?\DateTimeInterface
    {
        return ($job->trigger ?? 'cron') === 'cron' && $job->cron && $job->enabled !== false
            ? (new CronExpression($job->cron))->getNextRunDate(now(), 0, false, $job->timezone ?: 'UTC')
            : null;
    }

    /** The next $count run times, for the schedule preview. */
    public static function nextRuns(string $cron, string $timezone = 'UTC', int $count = 3): array
    {
        return array_map(fn ($d) => \Illuminate\Support\Carbon::instance($d)->utc()->toIso8601ZuluString(),
            (new CronExpression($cron))->getMultipleRunDates($count, now(), false, false, $timezone));
    }

    public function refreshNextRun(ScheduledJob $job): void
    {
        $job->update(['next_run_at' => $this->nextRun($job)]);
    }

    public function runDue(): int
    {
        $count = 0;
        foreach (ScheduledJob::allTenants()->where('enabled', true)->where('trigger', 'cron')->whereNotNull('cron')->get() as $job) {
            app(Tenant::class)->as($job->organization_id, function () use ($job, &$count) {
                // CRON-5: the scheduler itself was down at the scheduled time.
                if (in_array($job->action, self::ENGINE, true) && $job->next_run_at && $job->next_run_at->lt(now()->subMinutes(2))) {
                    $this->missed($job, 'The scheduler was not running at '.$job->next_run_at->toDayDateTimeString().' UTC.');
                    $this->refreshNextRun($job);
                }
                if ((new CronExpression($job->cron))->isDue(now(), $job->timezone ?: 'UTC')) {
                    $this->run($job);
                    $count++;
                }
            });
        }
        $count += $this->runRetries();
        $this->checkLongRunning();

        return $count;
    }

    /** CRON-2: failed runs whose backoff has passed are tried again. */
    private function runRetries(): int
    {
        $count = 0;
        foreach (\App\Models\ScheduledJobRun::allTenants()->whereNotNull('retry_at')->where('retry_at', '<=', now())->get() as $failed) {
            $failed->update(['retry_at' => null]);
            $job = ScheduledJob::allTenants()->find($failed->scheduled_job_id);
            if ($job && $job->enabled) {
                app(Tenant::class)->as($job->organization_id, fn () => $this->run($job, 'retry', $failed->attempt + 1));
                $count++;
            }
        }

        return $count;
    }

    /** CRON-5: runs going on longer than the job allows. */
    private function checkLongRunning(): void
    {
        $runs = \App\Models\ScheduledJobRun::allTenants()->where('status', 'running')->where('long_alerted', false)->get();
        foreach ($runs as $run) {
            $job = ScheduledJob::allTenants()->find($run->scheduled_job_id);
            if ($job?->long_after && $run->started_at->lt(now()->subSeconds($job->long_after))) {
                $run->update(['long_alerted' => true]);
                $this->alert($job, "Scheduled job \"{$job->name}\" is still running", 'It started at '.$run->started_at->toDayDateTimeString().' UTC, longer than the '.$job->long_after.' s it is allowed.', 'warning');
            }
        }
    }

    /** $source: schedule, manual (Run now), test (Test run: no alerts, no overlap check) or retry. */
    public function run(ScheduledJob $scheduled, string $source = 'schedule', int $attempt = 1): Job
    {
        $params = $scheduled->params ?? [];
        $job = $this->jobs->create('scheduled.'.$scheduled->action, ['execute'], $scheduled);
        if (in_array($scheduled->action, self::ENGINE, true)) {
            return $this->runEngine($scheduled, $job, $source, $attempt);
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

    public static function mode(ScheduledJob $job): string
    {
        return $job->run_mode ?: match ($job->action) {
            'url' => 'http',
            'server_command' => 'host',
            default => 'container',
        };
    }

    /**
     * CRON-1/2/3: one run of an engine job. Applies the overlap policy, records the run
     * (exit code, last 64 KB of output) and hands the command to the agent, or calls the URL.
     */
    private function runEngine(ScheduledJob $scheduled, Job $job, string $source, int $attempt): Job
    {
        $Run = \App\Models\ScheduledJobRun::class;
        $running = $Run::allTenants()->where('scheduled_job_id', $scheduled->id)->where('status', 'running')
            ->where('started_at', '>', now()->subSeconds(($scheduled->timeout ?: 600) + 120))->get();
        if ($source !== 'test' && $running->isNotEmpty()) {
            if ($scheduled->overlap === 'skip') {
                $Run::allTenants()->create([
                    'organization_id' => $scheduled->organization_id, 'scheduled_job_id' => $scheduled->id, 'status' => 'skipped',
                    'source' => $source, 'job_id' => $job->id, 'attempt' => $attempt, 'started_at' => now(), 'finished_at' => now(),
                    'output' => 'Skipped: the previous run is still going.',
                ]);
                $this->jobs->succeed($job, ['skipped' => true]);

                return $job->fresh();
            }
            if ($scheduled->overlap === 'stop') {
                foreach ($running as $old) {
                    $this->stop($scheduled, $old);
                }
            }
        }
        $run = $Run::allTenants()->create([
            'organization_id' => $scheduled->organization_id, 'scheduled_job_id' => $scheduled->id, 'status' => 'running',
            'source' => $source, 'job_id' => $job->id, 'attempt' => $attempt, 'started_at' => now(),
        ]);
        $scheduled->update(['last_run_at' => now(), 'last_status' => 'running', 'next_run_at' => $this->nextRun($scheduled)]);
        $mode = self::mode($scheduled);
        if ($mode === 'http') {
            return $this->runHttp($scheduled, $run, $job);
        }

        $payload = ['run_id' => (string) $run->id, 'mode' => $mode, 'command' => (string) ($scheduled->params['command'] ?? ''), 'timeout' => (int) ($scheduled->timeout ?: 600)];
        if ($mode === 'host') {
            $server = Server::allTenants()->find($scheduled->server_id);
        } else {
            $app = \App\Models\Application::allTenants()->find($scheduled->application_id);
            $server = $app?->server_id ? Server::allTenants()->find($app->server_id) : null;
            if ($app) {
                $payload['user'] = self::execUser($app);
                if ($mode === 'oneoff') {
                    $payload += $this->oneOffSpec($app);
                } else {
                    $container = Container::allTenants()->where('application_id', $app->id)->where('status', 'running')->first();
                    $payload['container'] = $container?->name ?? $app->containerName();
                }
            }
        }
        if (! $server || ! $server->isOnline()) {
            // CRON-5: the runner (the server's agent) is down at the scheduled time.
            $this->finishRun($scheduled, $run, $job, -1, 'The server that runs this job is offline.', 0, false, 'missed');

            return $job->fresh();
        }
        $this->bus->dispatch($server, 'cron.exec', $payload, $job);

        return $job;
    }

    /** CRON-1: HTTP mode, with method, headers and the status that counts as success. */
    private function runHttp(ScheduledJob $scheduled, \App\Models\ScheduledJobRun $run, Job $job): Job
    {
        $p = $scheduled->params ?? [];
        $started = microtime(true);
        try {
            $res = Http::timeout(min(120, $scheduled->timeout ?: 30))
                ->withHeaders(['User-Agent' => 'WorkSuite-Cron/1.0'] + (array) ($p['headers'] ?? []))
                ->send(strtoupper($p['method'] ?? 'GET'), $p['url']);
            $expected = (int) ($p['expected_status'] ?? 0);
            $ok = $expected ? $res->status() === $expected : $res->successful();
            $this->finishRun($scheduled, $run, $job, $ok ? 0 : $res->status(), 'HTTP '.$res->status()."\n".mb_strcut($res->body(), 0, 4096), (int) ((microtime(true) - $started) * 1000));
        } catch (\Throwable $e) {
            $this->finishRun($scheduled, $run, $job, -1, $e->getMessage(), (int) ((microtime(true) - $started) * 1000));
        }

        return $job->fresh();
    }

    /** One-off runs use the app's image, environment and volumes (CRON-8). */
    private function oneOffSpec(\App\Models\Application $app): array
    {
        $env = \App\Models\EnvVar::allTenants()->where('application_id', $app->id)->get()->mapWithKeys(fn ($v) => [$v->key => (string) $v->value])->all();
        $release = $app->current_release_id ? \App\Models\Release::find($app->current_release_id) : null;
        $volume = $release?->config['volume_path'] ?? null;

        return [
            'image' => (string) ($release?->image ?? $app->image),
            'env' => (object) $env,
            'mounts' => $volume ? [['source' => 'app-'.$app->name.'-data', 'target' => $volume]] : [],
            'network' => 'proxy',
        ];
    }

    /** CRON-2 "stop the old run". */
    public function stop(ScheduledJob $scheduled, \App\Models\ScheduledJobRun $run): void
    {
        $command = \App\Models\AgentCommand::allTenants()->where('job_id', $run->job_id)->first();
        $server = $command ? Server::allTenants()->find($command->server_id) : null;
        if ($server) {
            $this->bus->dispatch($server, 'cron.stop', array_filter(['run_id' => (string) $run->id, 'mode' => $command->payload['mode'] ?? 'container', 'container' => $command->payload['container'] ?? null]));
        }
        $run->update(['status' => 'stopped', 'finished_at' => now(), 'output' => trim(($run->output ?? '')."\nStopped: a newer run started.")]);
    }

    /** CRON-5: one missed run, recorded and alerted. */
    private function missed(ScheduledJob $scheduled, string $why): void
    {
        \App\Models\ScheduledJobRun::allTenants()->create([
            'organization_id' => $scheduled->organization_id, 'scheduled_job_id' => $scheduled->id, 'status' => 'missed',
            'source' => 'schedule', 'started_at' => $scheduled->next_run_at ?? now(), 'finished_at' => now(), 'output' => $why,
        ]);
        $scheduled->update(['last_status' => 'missed', 'last_result' => 'Missed']);
        $this->alert($scheduled, "Scheduled job \"{$scheduled->name}\" missed a run", $why, 'warning');
    }

    /** CRON-5: email and webhook channels, each switchable per job. */
    private function alert(ScheduledJob $scheduled, string $title, string $message, string $severity = 'critical'): void
    {
        if ($scheduled->alert_email ?? true) {
            app(EmailTemplateService::class)->notifyOrganization($scheduled->organization_id, 'alert', ['title' => $title, 'message' => $message, 'severity' => $severity]);
        }
        if ($scheduled->alert_webhook ?? true) {
            foreach (\App\Models\NotificationChannel::allTenants()->where('organization_id', $scheduled->organization_id)->where('enabled', true)->where('type', '!=', 'email')->get() as $channel) {
                try {
                    app(NotificationService::class)->send($channel, $title, $message, ['scheduled_job_id' => $scheduled->id, 'severity' => $severity]);
                } catch (\Throwable) {
                    // a failing channel must not lose the run result
                }
            }
        }
    }

    /** CRON-8: the app's own non-root user; web images run as www-data. */
    public static function execUser(\App\Models\Application $app): string
    {
        return preg_match('/^(wordpress|php|drupal|joomla|nextcloud)[:@]/', (string) $app->image) ? '33:33' : '1000:1000';
    }

    public function onCommandFinished(\App\Models\AgentCommand $command, Job $job): void
    {
        if ($command->type === 'cron.stop') {
            return;
        }
        $run = \App\Models\ScheduledJobRun::allTenants()->where('job_id', $job->id)->first();
        $scheduled = $run ? ScheduledJob::allTenants()->find($run->scheduled_job_id) : null;
        if (! $run || ! $scheduled) {
            $this->jobs->fail($job, 'The scheduled job no longer exists.');

            return;
        }
        if ($run->status === 'stopped') {
            $this->jobs->fail($job, 'Stopped: a newer run started.');

            return;
        }
        $out = (array) $command->output;
        $command->status === 'succeeded'
            ? $this->finishRun($scheduled, $run, $job, (int) ($out['exit_code'] ?? 0), (string) ($out['output'] ?? ''), (int) ($out['duration_ms'] ?? 0), (bool) ($out['timed_out'] ?? false))
            : $this->finishRun($scheduled, $run, $job, -1, (string) $command->message, 0);
    }

    private function finishRun(ScheduledJob $scheduled, \App\Models\ScheduledJobRun $run, Job $job, int $exit, string $output, int $ms, bool $timedOut = false, ?string $status = null): void
    {
        $ok = $exit === 0;
        $status ??= $ok ? 'succeeded' : 'failed';
        $output = $this->redact($scheduled, $output);
        $test = $run->source === 'test';
        $retry = ! $ok && ! $test && $status === 'failed' && $run->attempt <= (int) $scheduled->retries;
        $run->update([
            'status' => $status, 'exit_code' => $exit, 'output' => mb_strcut($output, max(0, strlen($output) - 65536)), 'duration_ms' => $ms, 'finished_at' => now(),
            // CRON-2: backoff doubles with each attempt.
            'retry_at' => $retry ? now()->addSeconds(max(10, (int) $scheduled->retry_backoff) * 2 ** ($run->attempt - 1)) : null,
        ]);
        $result = $status === 'missed' ? 'Missed' : ($timedOut ? 'Timed out' : ($ok ? 'Exit 0' : "Exit {$exit}"));
        $scheduled->update(['last_status' => $ok ? 'succeeded' : $status, 'last_result' => $result]);
        $ok ? $this->jobs->succeed($job, ['exit_code' => $exit]) : $this->jobs->fail($job, $result);
        if (! $test) {
            $this->afterRun($scheduled, $ok, $retry, $status, $result, $output);
        }
        // CRON-3: keep 30 days of history.
        \App\Models\ScheduledJobRun::allTenants()->where('scheduled_job_id', $scheduled->id)->where('started_at', '<', now()->subDays(30))->delete();
    }

    /** CRON-5: alert on a final failure; three in a row open a Monitoring alert, a success closes it. */
    private function afterRun(ScheduledJob $scheduled, bool $ok, bool $retrying, string $status, string $result, string $output): void
    {
        if ($ok) {
            $scheduled->update(['failure_streak' => 0]);
            \App\Models\Alert::allTenants()->where('resource_type', 'ScheduledJob')->where('resource_id', $scheduled->id)->whereIn('status', ['open', 'acknowledged'])
                ->update(['status' => 'resolved', 'resolved_at' => now()]);

            return;
        }
        if ($retrying) {
            return;
        }
        $streak = $scheduled->failure_streak + 1;
        $scheduled->update(['failure_streak' => $streak]);
        if ($status === 'missed') {
            $this->alert($scheduled, "Scheduled job \"{$scheduled->name}\" missed a run", 'The server that runs it was offline at the scheduled time.', 'warning');
        } else {
            $this->alert($scheduled, "Scheduled job \"{$scheduled->name}\" failed", $result.($output !== '' ? "\n\n".mb_strcut($output, max(0, strlen($output) - 2000)) : ''));
        }
        $open = \App\Models\Alert::allTenants()->where('resource_type', 'ScheduledJob')->where('resource_id', $scheduled->id)->whereIn('status', ['open', 'acknowledged'])->exists();
        if ($streak >= self::FAILURE_ALERT_STREAK && ! $open) {
            \App\Models\Alert::allTenants()->create([
                'organization_id' => $scheduled->organization_id, 'resource_type' => 'ScheduledJob', 'resource_id' => $scheduled->id,
                'severity' => 'critical', 'title' => "Scheduled job \"{$scheduled->name}\" failed {$streak} times in a row", 'message' => $result,
            ]);
        }
    }

    /** CRON-8: secret values from the app's environment never reach the run history. */
    private function redact(ScheduledJob $scheduled, string $output): string
    {
        if (! $scheduled->application_id || $output === '') {
            return $output;
        }
        $secrets = \App\Models\EnvVar::allTenants()->where('application_id', $scheduled->application_id)->where('secret', true)->get()
            ->map(fn ($v) => (string) $v->value)->filter(fn ($v) => strlen($v) >= 4)->sortByDesc(fn ($v) => strlen($v))->values()->all();

        return $secrets ? str_replace($secrets, '********', $output) : $output;
    }
}
