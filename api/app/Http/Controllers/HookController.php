<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ScheduledJob;
use App\Services\EnvironmentService;
use Illuminate\Http\Request;
use App\Services\ScheduledJobService;
use App\Support\Tenant;

/** AUT-001 webhook trigger: POST /api/v1/hooks/scheduled-jobs/{id}/{token}. */
class HookController extends Controller
{
    public function scheduledJob(string $id, string $token, ScheduledJobService $service, Tenant $tenant)
    {
        $job = ScheduledJob::allTenants()->where('trigger', 'webhook')->where('enabled', true)->find($id);
        abort_unless($job && $job->webhook_token_hash && hash_equals($job->webhook_token_hash, hash('sha256', $token)), 404);
        $tenant->set($job->organization_id);

        return $this->accepted($service->run($job));
    }

    /**
     * ENV-4 / BLD-1: Git push webhook for a project. GitHub and Gitea sign the
     * body (X-Hub-Signature-256), GitLab sends the secret (X-Gitlab-Token).
     * Apps in environments mapped to the pushed branch are deployed.
     */
    public function git(Request $request, string $project, EnvironmentService $envs, Tenant $tenant)
    {
        $p = Project::allTenants()->find($project);
        abort_unless($p && $p->webhook_secret, 404);
        $sig = (string) $request->header('X-Hub-Signature-256', '');
        $token = (string) $request->header('X-Gitlab-Token', '');
        $ok = ($sig !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $p->webhook_secret), $sig))
            || ($token !== '' && hash_equals($p->webhook_secret, $token));
        abort_unless($ok, 401, 'Invalid webhook signature.');
        if (in_array($request->header('X-GitHub-Event', $request->header('X-Gitea-Event')), ['ping'], true)) {
            return response()->json(['data' => ['pong' => true]]);
        }
        $tenant->set($p->organization_id);
        // ENV: pull / merge requests open, update and close preview environments.
        $event = (string) ($request->header('X-GitHub-Event') ?? $request->header('X-Gitea-Event') ?? $request->header('X-Gitlab-Event'));
        if ($event === 'pull_request' || $event === 'Merge Request Hook') {
            $gitlab = $event === 'Merge Request Hook';
            $number = (int) ($gitlab ? $request->input('object_attributes.iid') : $request->input('number', $request->input('pull_request.number')));
            $action = (string) ($gitlab ? $request->input('object_attributes.action') : $request->input('action'));
            $branch = (string) ($gitlab ? $request->input('object_attributes.source_branch') : $request->input('pull_request.head.ref'));
            if ($number < 1) {
                return response()->json(['data' => ['ignored' => 'no pull request number']]);
            }
            if (in_array($action, ['closed', 'close', 'merge'], true)) {
                return response()->json(['data' => ['closed' => $envs->closePreview($p, $number)]]);
            }
            if (! in_array($action, ['opened', 'reopened', 'synchronize', 'open', 'reopen', 'update'], true) || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch)) {
                return response()->json(['data' => ['ignored' => $action]]);
            }
            $r = $envs->openPreview($p, $number, $branch);

            return response()->json(['data' => ['environment_id' => $r['environment']->id, 'deployments' => $r['deployments'], 'urls' => $r['urls']]], 202);
        }
        $ref = (string) $request->input('ref', '');
        if (! str_starts_with($ref, 'refs/heads/') || $request->input('deleted')) {
            return response()->json(['data' => ['deployments' => [], 'ignored' => 'not a branch push']]);
        }
        $repo = (string) ($request->input('repository.clone_url') ?? $request->input('repository.git_http_url') ?? $request->input('project.git_http_url') ?? '');
        $tenant->set($p->organization_id);

        return response()->json(['data' => ['deployments' => $envs->onPush($p, $repo, substr($ref, 11))]], 202);
    }

    /**
     * ENV-7: the sleeping page's request, forwarded by the app server's Traefik with the
     * site's host. It can only start an app that is asleep, which a visit is meant to do.
     */
    public function wake(Request $request, EnvironmentService $envs)
    {
        $host = strtolower((string) ($request->header('X-Forwarded-Host') ?: $request->input('host', '')));
        $host = explode(',', $host)[0];
        $domain = \App\Models\Domain::allTenants()->where('name', trim($host))->first();
        $app = $domain?->application_id ? \App\Models\Application::allTenants()->find($domain->application_id) : null;
        if (! $app?->asleep_at) {
            return response()->json(['data' => ['woken' => false]], 202);
        }
        app(Tenant::class)->as($app->organization_id, fn () => $envs->wakeApp($app));

        return response()->json(['data' => ['woken' => true]], 202);
    }
}
