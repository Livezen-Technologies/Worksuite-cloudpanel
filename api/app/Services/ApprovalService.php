<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\ApprovalRequired;
use App\Models\Approval;
use App\Models\User;
use App\Support\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Second-approver gate for destructive and sensitive actions (SRS 3, SET-002).
 * An action needs approval when the role grant is marked "+approval" and the
 * organisation's security.require_approval_destructive setting is on.
 */
class ApprovalService
{
    /** @var array<string, Closure(Approval, User): mixed> */
    private array $executors = [];

    private ?string $executing = null;

    public function __construct(private Authorizer $authorizer, private AuditLogger $audit) {}

    public function register(string $action, Closure $executor): void
    {
        $this->executors[$action] = $executor;
    }

    /**
     * Checks the permission; if it needs approval, parks the action and throws.
     * A requester may pass an approved, unused approval_id to proceed.
     */
    public function gate(User $user, string $permission, string $action, ?Model $resource, array $payload = [], ?string $approvalId = null): void
    {
        $grant = $this->authorizer->authorize($user, $permission, $resource, true);
        if ($this->executing !== null || ! $grant['requires_approval'] || ! $this->settingOn()) {
            return;
        }
        if ($approvalId) {
            $approval = Approval::whereKey($approvalId)->where('status', 'approved')->where('action', $action)
                ->where('requested_by', $user->id)->where('resource_id', $resource?->getKey())->first();
            if ($approval && empty($approval->result['consumed'])) {
                $approval->update(['result' => array_merge($approval->result ?? [], ['consumed' => true])]);

                return;
            }
            throw ApiException::forbidden('The approval is missing, not yet approved, or already used.');
        }

        $approval = Approval::create([
            'action' => $action,
            'resource_type' => $resource ? class_basename($resource) : null,
            'resource_id' => $resource?->getKey(),
            'payload' => $payload,
            'requested_by' => $user->id,
            'expires_at' => now()->addHours(config('platform.approval_ttl_hours')),
        ]);
        $this->audit->log('approval.requested', 'Approval', $approval->id, ['action' => $action, 'resource_id' => $resource?->getKey()]);

        throw new ApprovalRequired($approval);
    }

    public function approve(Approval $approval, User $approver): Approval
    {
        if ($approval->status !== 'pending' || ($approval->expires_at && $approval->expires_at->isPast())) {
            throw ApiException::conflict('This approval is no longer pending.');
        }
        if ($approval->requested_by === $approver->id) {
            throw ApiException::forbidden('A second person must approve this request.');
        }
        $this->authorizer->authorize($approver, 'approvals.decide', null, true);

        $approval->update(['status' => 'approved', 'decided_by' => $approver->id, 'decided_at' => now()]);
        $executor = $this->executors[$approval->action] ?? null;
        if ($executor) {
            $requester = User::findOrFail($approval->requested_by);
            $this->executing = $approval->id;
            try {
                $result = $executor($approval, $requester);
            } finally {
                $this->executing = null;
            }
            $approval->update(['result' => array_merge(['executed' => true], is_array($result) ? $result : [])]);
        }

        return $approval->fresh();
    }

    public function reject(Approval $approval, User $approver): Approval
    {
        if ($approval->status !== 'pending') {
            throw ApiException::conflict('This approval is no longer pending.');
        }
        $this->authorizer->authorize($approver, 'approvals.decide', null, true);
        $approval->update(['status' => 'rejected', 'decided_by' => $approver->id, 'decided_at' => now()]);

        return $approval;
    }

    private function settingOn(): bool
    {
        return (bool) app(Tenant::class)->organization()?->setting('security.require_approval_destructive', true);
    }
}
