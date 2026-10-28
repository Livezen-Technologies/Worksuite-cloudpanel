<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupArtifact;
use App\Models\BackupJob;
use App\Models\Database;
use App\Services\ApprovalService;
use App\Services\BackupService;
use App\Services\DatabaseService;
use App\Support\Lists;
use Illuminate\Http\Request;

class DatabaseController extends Controller
{
    public function __construct(private DatabaseService $databases, private BackupService $backups) {}

    public function index(Request $request)
    {
        return response()->json(Lists::paginate(Database::query(), $request, ['name'], ['name', 'created_at', 'size_mb'], ['engine', 'server_id', 'status'], fn ($d) => $this->databases->toApi($d)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9][a-z0-9_-]*$/'], 'engine' => 'required|in:postgres,mysql,mariadb,redis,mongodb',
            'version' => 'nullable|string|max:20', 'server_id' => 'required|uuid', 'environment' => 'sometimes|in:production,staging,development',
            'network' => 'nullable|string', 'memory_mb' => 'nullable|integer|min:64',
        ]);
        $result = $this->databases->create($data);

        return response()->json(['data' => ['job_id' => $result['job_id'], 'database' => $result['database']]], 202);
    }

    public function show(string $id)
    {
        return $this->data($this->databases->toApi(Database::findOrFail($id)));
    }

    public function update(Request $request, string $id)
    {
        $db = Database::findOrFail($id);
        $db->update($request->validate(['environment' => 'sometimes|in:production,staging,development']));

        return $this->data($this->databases->toApi($db));
    }

    public function destroy(Request $request, string $id, ApprovalService $approvals)
    {
        $db = Database::findOrFail($id);
        if ($db->environment === 'production') {
            $approvals->gate($request->user(), 'production.delete', 'database.delete', $db, [], $request->input('approval_id'));
        }
        $db->delete();

        return response()->noContent();
    }

    public function backup(string $id)
    {
        $db = Database::findOrFail($id);
        $backup = $this->backups->run(null, 'database', $db->id, 'manual');

        return $this->accepted(\App\Models\Job::find($backup->job_id), ['backup_job_id' => $backup->id]);
    }

    public function restore(Request $request, string $id)
    {
        $db = Database::findOrFail($id);
        $data = $request->validate(['artifact_id' => 'nullable|uuid']);
        $artifact = $data['artifact_id'] ?? null
            ? BackupArtifact::findOrFail($data['artifact_id'])
            : BackupArtifact::whereIn('backup_job_id', BackupJob::where('target_type', 'database')->where('target_id', $db->id)->select('id'))->latest()->firstOrFail();
        $restore = $this->backups->restore($artifact, $request->user()->id, true);

        return $this->accepted(\App\Models\Job::find($restore->job_id), ['restore_job_id' => $restore->id]);
    }
}
