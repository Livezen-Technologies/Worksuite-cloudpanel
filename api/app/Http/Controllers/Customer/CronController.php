<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ScheduledJob;
use App\Models\ScheduledJobRun;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Services\ScheduledJobService;
use App\Support\CronDescription;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * UCRON-1..4, CRON-1..8: the customer's scheduled jobs: a command in one of their apps
 * (in its container or a one-off container from its image), or a URL to call.
 * Admin\CronController extends this for every customer's jobs and the server mode (CRON-9).
 */
class CronController extends Controller
{
    public function __construct(protected ScheduledJobService $jobs, protected BillingService $billing, protected AuditLogger $audit) {}

    /** Run modes this caller may use. Customers never get the server's shell (CRON-6). */
    protected function modes(): array
    {
        return ['container', 'oneoff', 'http'];
    }

    protected function query(): Builder
    {
        return ScheduledJob::query()->whereIn('action', ['app_command', 'url']);
    }

    protected function apps(): Builder
    {
        return Application::where('kind', 'customer');
    }

    protected function enforceLimits(): bool
    {
        return true;
    }

    public function index()
    {
        $apps = $this->apps()->pluck('name', 'id');
        $jobs = $this->query()->orderBy('name')->get();

        return $this->data([
            'jobs' => $jobs->map(fn ($j) => $this->present($j, $apps)),
            'limit' => $this->billing->limit('cron_jobs', 10), 'used' => $jobs->count(),
            'min_interval_minutes' => $this->billing->limit('cron_min_minutes', 1),
            'max_runtime_seconds' => $this->billing->limit('cron_max_runtime', 3600),
            'modes' => $this->modes(),
            'apps' => $apps->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
        ]);
    }

    /** CRON-1: a human description of a schedule and its next three run times. */
    public function preview(Request $request)
    {
        $data = $request->validate(['cron' => 'required|string|max:100', 'timezone' => 'sometimes|timezone']);
        if (! CronExpression::isValidExpression($data['cron'])) {
            throw ApiException::validation(['cron' => ['Not a valid cron expression (five fields).']]);
        }
        $tz = $data['timezone'] ?? 'UTC';

        return $this->data(['description' => CronDescription::describe($data['cron']), 'timezone' => $tz, 'next' => ScheduledJobService::nextRuns($data['cron'], $tz)]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        if ($this->enforceLimits() && $this->query()->count() >= $this->billing->limit('cron_jobs', 10)) {
            throw ApiException::quota('Your plan allows '.$this->billing->limit('cron_jobs', 10).' scheduled jobs. Upgrade to add more.');
        }
        $job = ScheduledJob::create($data + ['trigger' => 'cron', 'enabled' => true]);
        $this->jobs->refreshNextRun($job);
        $this->audit->log('cron.create', 'scheduled_jobs', $job->id, ['name' => $job->name, 'action' => $job->action, 'run_mode' => $job->run_mode, 'cron' => $job->cron]);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($job->fresh()), 201);
    }

    public function update(Request $request, string $id)
    {
        $job = $this->find($id);
        $data = $this->validated($request, false, $job);
        $job->update($data);
        $this->jobs->refreshNextRun($job);
        $this->audit->log(isset($data['enabled']) && count($data) === 1 ? ($data['enabled'] ? 'cron.resume' : 'cron.pause') : 'cron.update', 'scheduled_jobs', $job->id,
            array_intersect_key($data, array_flip(['name', 'cron', 'enabled', 'timeout', 'run_mode', 'overlap', 'retries', 'timezone'])));
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($job->fresh()));
    }

    /** CRON-4: a copy, paused, so it does not run twice by surprise. */
    public function duplicate(Request $request, string $id)
    {
        $job = $this->find($id);
        if ($this->enforceLimits() && $this->query()->count() >= $this->billing->limit('cron_jobs', 10)) {
            throw ApiException::quota('Your plan allows '.$this->billing->limit('cron_jobs', 10).' scheduled jobs. Upgrade to add more.');
        }
        $copy = $job->replicate(['last_run_at', 'last_status', 'last_result', 'next_run_at', 'failure_streak', 'webhook_token_hash']);
        $copy->forceFill(['name' => mb_substr($job->name.' (copy)', 0, 120), 'enabled' => false, 'failure_streak' => 0])->save();
        $this->audit->log('cron.duplicate', 'scheduled_jobs', $copy->id, ['from' => $job->id, 'name' => $copy->name]);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($copy->fresh()), 201);
    }

    public function destroy(Request $request, string $id)
    {
        $job = $this->find($id);
        $job->delete();
        ScheduledJobRun::allTenants()->where('scheduled_job_id', $id)->delete();
        $this->audit->log('cron.delete', 'scheduled_jobs', $id, ['name' => $job->name]);
        $request->attributes->set('audit_skip', true);

        return response()->noContent();
    }

    /** UCRON-3 / CRON-4: Run now, or a Test run that raises no alerts and ignores the overlap policy. */
    public function run(Request $request, string $id)
    {
        $job = $this->find($id);
        $test = $request->boolean('test');
        $this->audit->log($test ? 'cron.test' : 'cron.run', 'scheduled_jobs', $job->id, ['name' => $job->name]);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->jobs->run($job, $test ? 'test' : 'manual'));
    }

    /** CRON-3: run history, searchable by output. */
    public function runs(Request $request, string $id)
    {
        $this->find($id);
        $q = ScheduledJobRun::allTenants()->where('scheduled_job_id', $id);
        if ($request->filled('search')) {
            $q->where('output', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->input('search')).'%');
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return $this->data($q->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get()->map(fn ($r) => [
            'id' => $r->id, 'status' => $r->status, 'exit_code' => $r->exit_code, 'duration_ms' => $r->duration_ms, 'source' => $r->source,
            'attempt' => $r->attempt, 'retry_at' => $r->retry_at?->toIso8601ZuluString(),
            'output' => $r->output, 'started_at' => $r->started_at?->toIso8601ZuluString(),
        ]));
    }

    /** CRON-3: one run's output as a text file (in JSON, as the client parses every response). */
    public function download(string $id, string $runId)
    {
        $job = $this->find($id);
        $run = ScheduledJobRun::allTenants()->where('scheduled_job_id', $job->id)->findOrFail($runId);
        $head = "# {$job->name} — run {$run->id} ({$run->status}, exit ".($run->exit_code ?? '—').")\n# started ".$run->started_at?->toIso8601ZuluString()."\n\n";

        return $this->data(['filename' => 'cron-'.\Illuminate\Support\Str::slug($job->name).'-'.$run->id.'.log', 'text' => $head.($run->output ?? '')]);
    }

    protected function validated(Request $request, bool $create, ?ScheduledJob $job = null): array
    {
        $data = $request->validate([
            'name' => ($create ? 'required' : 'sometimes').'|string|max:120',
            'action' => ($create ? 'required' : 'prohibited').'|in:app_command,url,server_command',
            'run_mode' => 'sometimes|in:'.implode(',', ScheduledJobService::MODES),
            'application_id' => 'nullable|string', 'server_id' => 'nullable|string',
            'command' => 'sometimes|string|max:4096', 'url' => 'sometimes|url:https,http|max:2000',
            'method' => 'sometimes|in:GET,POST,PUT,PATCH,DELETE,HEAD', 'headers' => 'sometimes|array|max:20', 'headers.*' => 'string|max:1000',
            'expected_status' => 'sometimes|nullable|integer|min:100|max:599',
            'cron' => ($create ? 'required' : 'sometimes').'|string|max:100',
            'timezone' => 'sometimes|timezone',
            'overlap' => 'sometimes|in:skip,parallel,stop',
            'retries' => 'sometimes|integer|min:0|max:5', 'retry_backoff' => 'sometimes|integer|min:10|max:3600',
            'long_after' => 'sometimes|nullable|integer|min:60|max:86400',
            'alert_email' => 'sometimes|boolean', 'alert_webhook' => 'sometimes|boolean',
            'timeout' => 'sometimes|integer|min:10|max:21600', 'enabled' => 'sometimes|boolean',
        ]);
        $action = $data['action'] ?? $job?->action;
        $mode = $data['run_mode'] ?? $job?->run_mode ?? match ($action) { 'url' => 'http', 'server_command' => 'host', default => 'container' };
        $expected = match ($mode) { 'http' => 'url', 'host' => 'server_command', default => 'app_command' };
        if ($action !== $expected) {
            throw ApiException::validation(['run_mode' => ["The {$mode} mode does not fit a {$action} job."]]);
        }
        if (! in_array($mode, $this->modes(), true) || ($action === 'server_command' && ! in_array('host', $this->modes(), true))) {
            throw ApiException::validation(['run_mode' => ['This run mode is not available to you.']]);
        }
        if ($this->enforceLimits() && isset($data['timeout']) && $data['timeout'] > ($max = $this->billing->limit('cron_max_runtime', 3600))) {
            throw ApiException::validation(['timeout' => ["Your plan allows a run of at most {$max} seconds."]]);
        }
        if (isset($data['cron'])) {
            if (! CronExpression::isValidExpression($data['cron'])) {
                throw ApiException::validation(['cron' => ['Not a valid cron expression (five fields).']]);
            }
            $cron = new CronExpression($data['cron']);
            // The shortest gap over the next few runs.
            $runs = array_map(fn ($d) => $d->getTimestamp(), $cron->getMultipleRunDates(6, now()));
            $gap = min(array_map(fn ($x, $y) => ($y - $x) / 60, array_slice($runs, 0, -1), array_slice($runs, 1)));
            if ($this->enforceLimits() && $gap < ($min = $this->billing->limit('cron_min_minutes', 1))) {
                throw ApiException::validation(['cron' => ["Your plan allows a job at most every {$min} minutes."]]);
            }
        }
        $out = array_intersect_key($data, array_flip(['name', 'action', 'cron', 'timeout', 'enabled', 'timezone', 'overlap', 'retries', 'retry_backoff', 'long_after', 'alert_email', 'alert_webhook']));
        $out['run_mode'] = $mode;
        $params = $job?->params ?? [];
        if ($action === 'app_command') {
            if ($create || isset($data['application_id'])) {
                $app = $this->apps()->find($data['application_id'] ?? null) ?? throw ApiException::validation(['application_id' => ['Choose one of your apps.']]);
                $out['application_id'] = $app->id;
            }
        } elseif ($action === 'server_command') {
            if ($create || isset($data['server_id'])) {
                $server = \App\Models\Server::allTenants()->find($data['server_id'] ?? null) ?? throw ApiException::validation(['server_id' => ['Choose a server.']]);
                $out['server_id'] = $server->id;
            }
        }
        if ($action === 'url') {
            if ($create && empty($data['url'])) {
                throw ApiException::validation(['url' => ['Enter the URL to call.']]);
            }
            $params = array_replace($params, array_intersect_key($data, array_flip(['url', 'method', 'headers', 'expected_status'])));
        } else {
            if ($create && empty($data['command'])) {
                throw ApiException::validation(['command' => ['Enter the command to run.']]);
            }
            isset($data['command']) && $params['command'] = $data['command'];
        }
        if ($params !== ($job?->params ?? [])) {
            $out['params'] = $params;
        }

        return $out;
    }

    protected function find(string $id): ScheduledJob
    {
        return $this->query()->findOrFail($id);
    }

    protected function present(ScheduledJob $j, $apps = null): array
    {
        $apps ??= $this->apps()->pluck('name', 'id');

        return [
            'id' => $j->id, 'name' => $j->name, 'action' => $j->action, 'run_mode' => ScheduledJobService::mode($j),
            'cron' => $j->cron, 'schedule' => $j->cron ? CronDescription::describe($j->cron) : null, 'timezone' => $j->timezone ?: 'UTC',
            'enabled' => $j->enabled, 'timeout' => $j->timeout,
            'overlap' => $j->overlap ?: 'skip', 'retries' => (int) $j->retries, 'retry_backoff' => (int) $j->retry_backoff, 'long_after' => $j->long_after,
            'alert_email' => $j->alert_email ?? true, 'alert_webhook' => $j->alert_webhook ?? true, 'failure_streak' => (int) $j->failure_streak,
            'application_id' => $j->application_id, 'app' => $apps[$j->application_id] ?? null, 'server_id' => $j->server_id,
            'command' => $j->params['command'] ?? null, 'url' => $j->params['url'] ?? null,
            'method' => $j->params['method'] ?? ($j->action === 'url' ? 'GET' : null), 'headers' => $j->params['headers'] ?? null, 'expected_status' => $j->params['expected_status'] ?? null,
            'last_run_at' => $j->last_run_at?->toIso8601ZuluString(), 'last_status' => $j->last_status, 'last_result' => $j->last_result,
            'next_run_at' => $j->next_run_at?->toIso8601ZuluString(),
        ];
    }
}
