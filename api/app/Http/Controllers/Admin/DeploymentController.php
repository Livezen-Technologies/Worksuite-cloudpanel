<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Job;
use App\Services\Authorizer;
use App\Services\DeploymentService;
use App\Support\Lists;
use Illuminate\Http\Request;

class DeploymentController extends Controller
{
    public function __construct(private DeploymentService $deployments) {}

    public function index(Request $request)
    {
        $query = Deployment::with(['application:id,name,source_type', 'user:id,name,email']);
        app(Authorizer::class)->scopeAssigned($request->user(), 'resources.view', $query);

        return response()->json(Lists::paginate($query, $request, [], ['created_at', 'status'], ['status', 'application_id', 'source'], fn ($d) => $this->present($d)));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['application_id' => 'required|uuid']);
        $app = Application::findOrFail($data['application_id']);
        $this->authorizeFor($request, 'deploy', $app);
        $deployment = $this->deployments->start($app, $request->user(), $request->input('source', 'manual') === 'webhook' ? 'webhook' : 'manual');

        return $this->accepted($deployment->job->fresh(), ['deployment_id' => $deployment->id, 'deployment_status' => $deployment->fresh()->status]);
    }

    public function show(Request $request, string $id)
    {
        $deployment = Deployment::with(['application:id,name,source_type', 'user:id,name,email'])->findOrFail($id);
        $this->authorizeFor($request, 'resources.view', $deployment);

        return $this->data($this->present($deployment, true));
    }

    public function approve(Request $request, string $id)
    {
        $deployment = Deployment::findOrFail($id);
        $this->authorizeFor($request, 'approvals.decide');
        $deployment = $this->deployments->approve($deployment, $request->user());

        return $this->accepted($deployment->job->fresh(), ['deployment_id' => $deployment->id]);
    }

    private function present(Deployment $d, bool $withJob = false): array
    {
        $data = array_merge($d->toArray(), [
            'application' => $d->application?->name,
            'author' => $d->user?->name ?? 'system',
            'duration_seconds' => $d->started_at && $d->finished_at ? (int) $d->started_at->diffInSeconds($d->finished_at) : null,
        ]);
        if ($withJob) {
            $data['job'] = Job::find($d->job_id)?->toApi();
            $data['stages'] = $data['job']['stages'] ?? [];
        }

        return $data;
    }
}
