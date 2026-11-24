<?php

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Services\EnterpriseUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The deployment page's Updates tab (docs/ENTERPRISE_API.md "Updates"): state, settings, update now or at a
 * time, cancel a scheduled update, and put a run back (previous tag + its pre-update backup). Backups
 * themselves stay on the Backup endpoints (DeploymentProjectController); pre-update runs show there with
 * type pre_update.
 */
class DeploymentUpdateController extends Controller
{
    use FindsDeployment;

    private const VERSION = 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$/';

    public function __construct(private EnterpriseUpdateService $updates) {}

    public function show(string $ref)
    {
        return $this->data($this->updates->state($this->find($ref)));
    }

    /** Refresh: scan the deployment's node and the enterprise nodes now (bounded wait), then the fresh state. */
    public function refresh(string $ref)
    {
        return $this->data($this->updates->refresh($this->find($ref)));
    }

    public function settings(Request $request, string $ref)
    {
        $v = $request->validate([
            'mode' => ['sometimes', Rule::in(EnterpriseUpdateService::MODES)],
            'channel' => ['sometimes', 'string', 'regex:/^(latest|\d+\.\d+)$/'],
            'window' => 'sometimes|array',
            'window.days' => 'sometimes|array|min:1|max:7', 'window.days.*' => 'integer|between:1,7',
            'window.start' => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'window.duration_minutes' => 'sometimes|integer|between:30,720',
            'window.timezone' => 'sometimes|string|timezone:all',
            'notice_hours' => ['sometimes', 'integer', Rule::in(EnterpriseUpdateService::NOTICE_HOURS)],
            'restore_data_on_rollback' => 'sometimes|boolean',
        ]);
        $dep = $this->find($ref);
        $this->updates->saveSettings($dep, $v, $request->user()?->id);

        return $this->data($this->updates->state($dep->fresh()));
    }

    public function store(Request $request, string $ref)
    {
        $v = $request->validate([
            'version' => ['required', 'string', self::VERSION],
            'scheduled_for' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
            'requested_by' => 'nullable|string|max:191',
        ]);
        $at = ! empty($v['scheduled_for']) ? Carbon::parse($v['scheduled_for'])->utc() : null;
        if ($at && $at->lt(now()->subMinute())) {
            throw \App\Exceptions\ApiException::validation(['scheduled_for' => ['Pick a time in the future.']]);
        }
        $user = $request->user();
        if ($user && ! empty($v['requested_by'])) {
            $user = (clone $user)->forceFill(['name' => $v['requested_by']]); // the Cloud Admin person, for the history only
        }
        $run = $this->updates->request($this->find($ref), $v['version'], $at, $at ? 'scheduled' : 'manual', $user, $v['reason'] ?? null);

        return $this->data($this->updates->present($run), 202);
    }

    public function cancel(Request $request, string $ref, string $updateId)
    {
        return $this->data($this->updates->present($this->updates->cancel($this->find($ref), $updateId, $request->user())));
    }

    public function restore(Request $request, string $ref, string $updateId)
    {
        $v = $request->validate(['confirm' => 'accepted', 'reason' => 'required|string|min:3|max:500']);

        return $this->data($this->updates->restore($this->find($ref), $updateId, $request->user(), $v['reason']), 202);
    }
}
