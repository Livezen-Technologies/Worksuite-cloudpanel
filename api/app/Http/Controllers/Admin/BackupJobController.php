<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupArtifact;
use App\Models\BackupJob;
use App\Models\BackupPolicy;
use App\Models\Job;
use App\Services\BackupService;
use App\Support\Lists;
use Illuminate\Http\Request;

class BackupJobController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(BackupJob::with('artifacts'), $request, [], ['created_at', 'status'], ['status', 'backup_policy_id', 'target_type', 'target_id', 'application_id']));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['policy_id' => 'required|uuid']);
        $policy = BackupPolicy::findOrFail($data['policy_id']);
        $backup = $this->backups->run($policy, $policy->target_type, $policy->target_id, 'manual');

        return $this->accepted(Job::find($backup->job_id), ['backup_job_id' => $backup->id]);
    }

    public function artifacts(Request $request)
    {
        // BKM-005: filter by target (type and id), or by policy.
        $q = BackupArtifact::query()->with('backupJob:id,target_type,target_id');
        if ($request->filled('target_type') || $request->filled('target_id')) {
            $q->whereHas('backupJob', fn ($j) => $j->when($request->input('target_type'), fn ($j, $v) => $j->where('target_type', $v))
                ->when($request->input('target_id'), fn ($j, $v) => $j->where('target_id', $v)));
        }

        return response()->json(Lists::paginate($q, $request, [], ['created_at', 'size_mb'], ['backup_job_id', 'backup_policy_id', 'application_id', 'verified'],
            fn (BackupArtifact $a) => $a->toArray() + ['target_type' => $a->backupJob?->target_type, 'target_id' => $a->backupJob?->target_id]));
    }

    /** BKM-001 */
    public function summary()
    {
        return $this->data($this->backups->summary());
    }

    /** BKM-004: Run all due, or all failed. */
    public function runAll(Request $request)
    {
        $data = $request->validate(['which' => 'required|in:due,failed']);
        $started = $this->backups->runAll($data['which']);

        return $this->data(['started' => count($started), 'backup_job_ids' => $started], 202);
    }

    /** BKM-007: connection test and usage for a policy's destination. */
    public function probe(Request $request, string $id)
    {
        return $this->accepted($this->backups->probe(BackupPolicy::findOrFail($id), $request->user()->id), ['policy_id' => $id]);
    }

    public function restore(Request $request)
    {
        $data = $request->validate([
            'artifact_id' => 'required|uuid', 'fresh_backup' => 'sometimes|boolean',
            // BKM-006: the restore wizard's choices.
            'scope' => 'sometimes|in:everything,files,database', 'mode' => 'sometimes|in:replace,side_by_side',
            'stop_app' => 'sometimes|boolean', 'health_check' => 'sometimes|boolean',
        ]);
        $artifact = BackupArtifact::findOrFail($data['artifact_id']);
        $restore = $this->backups->restore($artifact, $request->user()->id, $data['fresh_backup'] ?? true, $data);

        return $this->accepted(Job::find($restore->job_id), ['restore_job_id' => $restore->id]);
    }

    /** UBAK-002 / admin: signed download URL (202 + job while the export runs). */
    public function download(Request $request, string $id)
    {
        $result = $this->backups->download(BackupArtifact::findOrFail($id), $request->user()->id);

        return response()->json(['data' => $result], $result['status'] === 'ready' ? 200 : 202);
    }

    /** BAK-005: run a restore test now. */
    public function restoreTest(Request $request, string $id)
    {
        $job = $this->backups->restoreTest(BackupArtifact::findOrFail($id), $request->user()->id);

        return $this->accepted($job, ['artifact_id' => $id]);
    }
}
