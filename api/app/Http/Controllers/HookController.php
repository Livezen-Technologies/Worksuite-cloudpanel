<?php

namespace App\Http\Controllers;

use App\Models\ScheduledJob;
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
}
