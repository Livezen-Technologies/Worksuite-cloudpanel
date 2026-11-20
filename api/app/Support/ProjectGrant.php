<?php

namespace App\Support;

/**
 * A request-scoped project role handed out by a caller that has already authorised the
 * request some other way. Used by the enterprise service API (perm:enterprise.deploy): it
 * resolves the deployment inside the tenant, then calls the ordinary project controllers
 * for that one project under this grant, so ProjectAccess and ProjectDomainService answer
 * as they would for a platform administrator of that project and nothing else.
 */
class ProjectGrant
{
    /** @var array<string, string> project id => role */
    private array $grants = [];

    public function role(?string $projectId): ?string
    {
        return $projectId !== null ? ($this->grants[$projectId] ?? null) : null;
    }

    /** Run a callback with the role granted on one project, then restore. */
    public function as(string $projectId, string $role, callable $callback): mixed
    {
        $previous = $this->grants[$projectId] ?? null;
        $this->grants[$projectId] = $role;
        try {
            return $callback();
        } finally {
            if ($previous === null) {
                unset($this->grants[$projectId]);
            } else {
                $this->grants[$projectId] = $previous;
            }
        }
    }
}
