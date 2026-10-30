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
use Cron\CronExpression;
use Illuminate\Http\Request;

/** UCRON-1..4: the customer's scheduled jobs: a command in one of their apps, or a URL to call. */
class CronController extends Controller
{
    public function __construct(private ScheduledJobService $jobs, private BillingService $billing, private AuditLogger $audit) {}

    public function index()
    {
        $apps = Application::where('kind', 'customer')->pluck('name', 'id');
        $jobs = ScheduledJob::query()->whereIn('action', ['app_command', 'url'])->orderBy('name')->get();

        return $this->data([
            'jobs' => $jobs->map(fn ($j) => $this->present($j, $apps)),
            'limit' => $this->billing->limit('cron_jobs', 10), 'used' => $jobs->count(),
            'min_interval_minutes' => $this->billing->limit('cron_min_minutes', 1),
            'apps' => $apps->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        if (ScheduledJob::query()->whereIn('action', ['app_command', 'url'])->count() >= $this->billing->limit('cron_jobs', 10)) {
            throw ApiException::quota('Your plan allows '.$this->billing->limit('cron_jobs', 10).' scheduled jobs. Upgrade to add more.');
        }
        $job = ScheduledJob::create($data + ['trigger' => 'cron', 'enabled' => true]);
        $this->jobs->refreshNextRun($job);
        $this->audit->log('cron.create', 'scheduled_jobs', $job->id, ['name' => $job->name, 'action' => $job->action, 'cron' => $job->cron]);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($job->fresh()), 201);
    }

    public function update(Request $request, string $id)
    {
        $job = $this->find($id);
        $data = $this->validated($request, false, $job);
        $job->update($data);
        $this->jobs->refreshNextRun($job);
        $this->audit->log('cron.update', 'scheduled_jobs', $job->id, array_intersect_key($data, array_flip(['name', 'cron', 'enabled', 'timeout'])));
        $request->attributes->set('audit_skip', true);

        return $this->data($this->present($job->fresh()));
    }

    public function destroy(Request $request, string $id)
    {
        $job = $this->find($id);
        $job->delete();
        ScheduledJobRun::where('scheduled_job_id', $id)->delete();
        $this->audit->log('cron.delete', 'scheduled_jobs', $id, ['name' => $job->name]);
        $request->attributes->set('audit_skip', true);

        return response()->noContent();
    }

    /** UCRON-3 */
    public function run(Request $request, string $id)
    {
        $job = $this->find($id);
        $this->audit->log('cron.run', 'scheduled_jobs', $job->id, ['name' => $job->name]);
        $request->attributes->set('audit_skip', true);

        return $this->accepted($this->jobs->run($job, 'manual'));
    }

    public function runs(string $id)
    {
        $this->find($id);

        return $this->data(ScheduledJobRun::where('scheduled_job_id', $id)->orderByDesc('started_at')->orderByDesc('id')->limit(50)->get()->map(fn ($r) => [
            'id' => $r->id, 'status' => $r->status, 'exit_code' => $r->exit_code, 'duration_ms' => $r->duration_ms, 'source' => $r->source,
            'output' => $r->output, 'started_at' => $r->started_at?->toIso8601ZuluString(),
        ]));
    }

    private function validated(Request $request, bool $create, ?ScheduledJob $job = null): array
    {
        $data = $request->validate([
            'name' => ($create ? 'required' : 'sometimes').'|string|max:120',
            'action' => ($create ? 'required' : 'prohibited').'|in:app_command,url',
            'application_id' => 'required_if:action,app_command|nullable|string',
            'command' => 'sometimes|string|max:4096', 'url' => 'sometimes|url:https,http|max:2000',
            'cron' => ($create ? 'required' : 'sometimes').'|string|max:100',
            'timeout' => 'sometimes|integer|min:10|max:3600', 'enabled' => 'sometimes|boolean',
        ]);
        $action = $data['action'] ?? $job?->action;
        if (isset($data['cron'])) {
            if (! CronExpression::isValidExpression($data['cron'])) {
                throw ApiException::validation(['cron' => ['Not a valid cron expression (five fields).']]);
            }
            $cron = new CronExpression($data['cron']);
            // The shortest gap over the next few runs.
            $runs = array_map(fn ($d) => $d->getTimestamp(), $cron->getMultipleRunDates(6, now()));
            $gap = min(array_map(fn ($x, $y) => ($y - $x) / 60, array_slice($runs, 0, -1), array_slice($runs, 1)));
            if ($gap < ($min = $this->billing->limit('cron_min_minutes', 1))) {
                throw ApiException::validation(['cron' => ["Your plan allows a job at most every {$min} minutes."]]);
            }
        }
        $out = array_intersect_key($data, array_flip(['name', 'action', 'cron', 'timeout', 'enabled']));
        if ($action === 'app_command') {
            if ($create || isset($data['application_id'])) {
                $app = Application::where('kind', 'customer')->find($data['application_id'] ?? null) ?? throw ApiException::validation(['application_id' => ['Choose one of your apps.']]);
                $out['application_id'] = $app->id;
            }
            if ($create && empty($data['command'])) {
                throw ApiException::validation(['command' => ['Enter the command to run.']]);
            }
            if (isset($data['command'])) {
                $out['params'] = ['command' => $data['command']];
            }
        } else {
            if ($create && empty($data['url'])) {
                throw ApiException::validation(['url' => ['Enter the URL to call.']]);
            }
            if (isset($data['url'])) {
                $out['params'] = ['url' => $data['url']];
            }
        }

        return $out;
    }

    private function find(string $id): ScheduledJob
    {
        return ScheduledJob::query()->whereIn('action', ['app_command', 'url'])->findOrFail($id);
    }

    private function present(ScheduledJob $j, $apps = null): array
    {
        $apps ??= Application::where('kind', 'customer')->pluck('name', 'id');

        return [
            'id' => $j->id, 'name' => $j->name, 'action' => $j->action, 'cron' => $j->cron, 'enabled' => $j->enabled, 'timeout' => $j->timeout,
            'application_id' => $j->application_id, 'app' => $apps[$j->application_id] ?? null,
            'command' => $j->params['command'] ?? null, 'url' => $j->params['url'] ?? null,
            'last_run_at' => $j->last_run_at?->toIso8601ZuluString(), 'last_status' => $j->last_status, 'last_result' => $j->last_result,
            'next_run_at' => $j->next_run_at?->toIso8601ZuluString(),
        ];
    }
}
