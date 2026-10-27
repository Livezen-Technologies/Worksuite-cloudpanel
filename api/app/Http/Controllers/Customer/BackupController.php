<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\BackupArtifact;
use App\Models\Job;
use App\Services\BackupService;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

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

    public function download(string $id)
    {
        $artifact = $this->find($id);
        $expires = now()->addMinutes(15);

        return $this->data(['url' => URL::temporarySignedRoute('backup.download', $expires, ['id' => $artifact->id]), 'expires_at' => $expires->toIso8601ZuluString()]);
    }

    private function find(string $id): BackupArtifact
    {
        return BackupArtifact::whereIn('application_id', Application::where('kind', 'customer')->select('id'))->findOrFail($id);
    }
}
