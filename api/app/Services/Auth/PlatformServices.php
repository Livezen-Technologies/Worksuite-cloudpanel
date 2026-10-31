<?php

namespace App\Services\Auth;

use App\Models\Integration;
use App\Models\Organization;

/**
 * SVC-5: third-party services the platform itself uses (sign-in apps, captcha), as set up
 * by staff in Third-party services. They belong to the platform organisation (the first
 * one created); disabled or unconfigured services read as null.
 */
class PlatformServices
{
    private ?string $orgId = null;

    public function orgId(): ?string
    {
        return $this->orgId ??= Organization::query()->orderBy('created_at')->value('id');
    }

    /** ['config' => [...], 'credentials' => [...]] for an enabled service, or null. */
    public function get(string $key): ?array
    {
        $row = Integration::allTenants()->where('organization_id', $this->orgId())->where('provider', $key)->where('enabled', true)->first();

        return $row ? ['config' => $row->config ?? [], 'credentials' => $row->credentials ?? []] : null;
    }
}
