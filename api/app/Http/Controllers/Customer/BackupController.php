<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\Job;
use App\Services\BackupService;
use App\Services\BillingService;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(Request $request)
    {
        $query = BackupArtifact::whereIn('application_id', Application::where('kind', 'customer')->select('id'))->latest();
        if ($app = $request->query('app_id')) {
            $query->where('application_id', $app);
        }
        $names = Application::where('kind', 'customer')->pluck('name', 'id');
        $items = $query->get()->map(fn (BackupArtifact $a) => [
            'id' => $a->id, 'app_id' => $a->application_id, 'app_name' => $names[$a->application_id] ?? null, 'size_mb' => $a->size_mb,
            'verified' => $a->verified, 'encrypted' => $a->encrypted, 'trigger' => $a->backupJob?->trigger,
            'created_at' => $a->created_at?->toIso8601ZuluString(), 'expires_at' => $a->expires_at?->toIso8601ZuluString(),
            'verified_at' => $a->verified_at?->toIso8601ZuluString(),
            'restore_test' => $a->restore_test_status ? ['status' => $a->restore_test_status, 'tested_at' => $a->restore_tested_at?->toIso8601ZuluString(), 'message' => $a->restore_test_message] : null,
            'export_status' => $a->export_status,
        ]);

        return response()->json(['data' => $items, 'meta' => ['page' => 1, 'per_page' => $items->count(), 'total' => $items->count()]]);
    }

    public function store(Request $request, BillingService $billing)
    {
        $data = $request->validate(['app_id' => 'required|uuid']);
        $app = Application::where('kind', 'customer')->findOrFail($data['app_id']);
        $billing->assertBackupAllowance();
        $backup = $this->backups->run(null, 'application', $app->id, 'manual');

        return $this->accepted(Job::find($backup->job_id), ['backup_job_id' => $backup->id]);
    }

    /** UBAK-003: takes a fresh backup first so the restore can be undone. */
    public function restore(Request $request, string $id)
    {
        $request->validate(['confirm' => 'sometimes|accepted']);
        $artifact = $this->find($id);
        $restore = $this->backups->restore($artifact, $request->user()->id, true);

        return $this->accepted(Job::find($restore->job_id), ['restore_job_id' => $restore->id]);
    }

    /** UBAK-002: 200 {url, expires_at} once the archive is exported; 202 {job_id} while it is. */
    public function download(Request $request, string $id)
    {
        $result = $this->backups->download($this->find($id), $request->user()->id);

        return response()->json(['data' => $result], $result['status'] === 'ready' ? 200 : 202);
    }

    private function find(string $id): BackupArtifact
    {
        return BackupArtifact::whereIn('application_id', Application::where('kind', 'customer')->select('id'))->findOrFail($id);
    }
}
