<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/** Binds the request to the token user's current organisation (SEC-001). */
class SetTenant
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $orgId = $token?->organization_id ?? null;
        $orgId = $orgId ?: $user?->current_organization_id;
        if (! $user || ! $orgId || ! $user->membership($orgId)) {
            throw ApiException::forbidden('You are not a member of an organisation.');
        }
        if ($user->current_organization_id !== $orgId) {
            $user->current_organization_id = $orgId;
        }
        $this->tenant->set($orgId);
        if (! $user->last_activity_at || $user->last_activity_at->lt(now()->subMinute())) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }
        $org = $this->tenant->organization();
        if ($org?->setting('security.require_mfa') && ! $user->mfa_enabled && ! $request->is('api/v1/me*', 'api/v1/auth/*')) {
            throw ApiException::forbidden('Your organisation requires two-factor authentication. Enable it under your account.');
        }

        return $next($request);
    }
}
