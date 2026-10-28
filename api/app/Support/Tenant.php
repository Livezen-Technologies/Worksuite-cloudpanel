<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Holds the organisation the current request acts in. Set by middleware
 * (users: users.current_organization_id, agents: the server's organisation).
 */
class Tenant
{
    private ?string $id = null;

    public function set(?string $organizationId): void
    {
        $this->id = $organizationId;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public static function current(): ?Organization
    {
        return app(self::class)->organization();
    }

    public function organization(): ?Organization
    {
        return $this->id ? Organization::find($this->id) : null;
    }

    /** Run a callback acting as another organisation, then restore. */
    public function as(?string $organizationId, callable $callback): mixed
    {
        $previous = $this->id;
        $this->id = $organizationId;
        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }
}
