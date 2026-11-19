<?php

namespace App\Http\Controllers;

use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Models\Project;
use App\Services\ProjectBackupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Project → Backups: history, run now, the automatic schedule, details and restore. Admin and customer. */
class ProjectBackupController extends Controller
{
    public const FREQUENCIES = ['hourly' => '0 * * * *', 'daily' => '30 20 * * *', 'weekly' => '30 20 * * 0'];   // daily = 02:00 Sri Lanka

    public function __construct(private ProjectBackupService $backups) {}

    public function index(string $id)
    {
        $project = Project::findOrFail($id);
        $runs = BackupJob::where('target_type', 'project')->where('project_id', $project->id)->latest()->limit(100)->get();
        $sizes = BackupJob::whereIn('parent_id', $runs->pluck('id'))->with('artifacts')->get()->groupBy('parent_id')
            ->map(fn ($c) => round((float) $c->flatMap->artifacts->sum('size_mb'), 2));
        $policy = $this->policy($project);

        return $this->data([
            'schedule' => $policy ? $this->presentPolicy($policy) : ['enabled' => false, 'frequency' => 'daily', 'retention_days' => 14, 'destination' => 'local', 'capacity_gb' => null, 'encrypted' => true],
            'backups' => $runs->map(fn (BackupJob $b) => [
                'id' => $b->id, 'type' => $b->trigger === 'schedule' ? 'scheduled' : $b->trigger, 'status' => $b->status, 'error' => $b->error,
                'created_at' => $b->created_at, 'finished_at' => $b->finished_at, 'size_mb' => $sizes[$b->id] ?? 0,
                'applications' => collect($b->metadata['applications'] ?? [])->pluck('name')->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request, string $id)
    {
        $data = $request->validate(['application_ids' => 'sometimes|array', 'application_ids.*' => 'uuid']);
        $project = Project::findOrFail($id);
        if ($request->is('api/v1/app/*')) {
            app(\App\Services\BillingService::class)->assertBackupAllowance();
        }
        $run = $this->backups->backup($project, 'manual', null, $data['application_ids'] ?? null);

        return $this->data(['id' => $run->id, 'status' => $run->status], 202);
    }

    public function schedule(Request $request, string $id)
    {
        $project = Project::findOrFail($id);
        $v = $request->validate([
            'enabled' => 'required|boolean',
            'frequency' => ['sometimes', Rule::in(array_keys(self::FREQUENCIES))],
            'retention_days' => 'sometimes|integer|min:1|max:3650',
            'destination' => 'sometimes|in:local,s3,b2,r2,gdrive,sftp',
            'capacity_gb' => 'sometimes|nullable|integer|min:1|max:1000000',
        ]);
        $policy = $this->policy($project) ?? new BackupPolicy(['organization_id' => $project->organization_id, 'name' => "Project {$project->name}", 'target_type' => 'project', 'target_id' => $project->id, 'method' => 'restic', 'encrypted' => true]);
        $policy->fill([
            'enabled' => $v['enabled'],
            'schedule' => self::FREQUENCIES[$v['frequency'] ?? $this->frequency($policy->schedule)],
            'retention_days' => $v['retention_days'] ?? $policy->retention_days ?? 14,
            'destination' => $v['destination'] ?? $policy->destination ?? 'local',
            'capacity_gb' => array_key_exists('capacity_gb', $v) ? $v['capacity_gb'] : $policy->capacity_gb,
            'encrypted' => true,
        ])->save();

        return $this->data($this->presentPolicy($policy->fresh()));
    }

    public function show(string $id)
    {
        return $this->data($this->backups->details($this->run($id)));
    }

    public function restore(Request $request, string $id)
    {
        $v = $request->validate([
            'confirm' => 'accepted',
            'scope' => ['required', Rule::in(['project', 'applications', 'database', 'volumes'])],
            'application_ids' => 'required_if:scope,applications|array', 'application_ids.*' => 'uuid',
            'mode' => ['sometimes', Rule::in(['replace', 'separate'])],
        ]);

        return $this->data($this->backups->restore($this->run($id), $request->user()?->id, $v['scope'], $v['application_ids'] ?? null, $v['mode'] ?? 'replace'), 202);
    }

    private function run(string $id): BackupJob
    {
        $run = BackupJob::where('target_type', 'project')->findOrFail($id);
        Project::findOrFail($run->project_id); // tenant check

        return $run;
    }

    private function policy(Project $project): ?BackupPolicy
    {
        return BackupPolicy::where('target_type', 'project')->where('target_id', $project->id)->first();
    }

    private function frequency(?string $cron): string
    {
        return array_search($cron, self::FREQUENCIES, true) ?: 'daily';
    }

    private function presentPolicy(BackupPolicy $p): array
    {
        return ['id' => $p->id, 'enabled' => (bool) $p->enabled, 'frequency' => $this->frequency($p->schedule), 'retention_days' => $p->retention_days,
            'destination' => $p->destination, 'capacity_gb' => $p->capacity_gb, 'encrypted' => true, 'last_run_at' => $p->last_run_at, 'last_status' => $p->last_status,
            'next_run_at' => \Cron\CronExpression::isValidExpression((string) $p->schedule) ? (new \Cron\CronExpression($p->schedule))->getNextRunDate(now())->format(DATE_ATOM) : null];
    }
}
