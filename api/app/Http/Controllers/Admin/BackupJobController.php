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
        return response()->json(Lists::paginate(BackupArtifact::query(), $request, [], ['created_at', 'size_mb'], ['backup_job_id', 'application_id', 'verified']));
    }

    public function restore(Request $request)
    {
        $data = $request->validate(['artifact_id' => 'required|uuid', 'fresh_backup' => 'sometimes|boolean']);
        $artifact = BackupArtifact::findOrFail($data['artifact_id']);
        $restore = $this->backups->restore($artifact, $request->user()->id, $data['fresh_backup'] ?? true);

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
