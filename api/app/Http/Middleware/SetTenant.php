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
        // CUS-3: a support session cannot change the owner's sign-in settings.
        if (\App\Support\Impersonation::staffFor($user) && \App\Support\Impersonation::blocks($request)) {
            throw \App\Exceptions\ApiException::forbidden('Support sessions cannot change sign-in settings. Ask the account owner.');
        }
        // MFA-POL: an authenticator app or a passkey satisfies the policy; account pages stay open so it can be set up.
        if ($org?->setting('security.require_mfa') && ! $user->hasSecondFactor() && ! $request->is('api/v1/me*', 'api/v1/auth/*')) {
            throw new ApiException('forbidden', 'Your organisation requires two-step verification. Set up an authenticator app or a passkey under your account.', 403, [], ['mfa_setup_required' => true]);
        }

        return $next($request);
    }
}
