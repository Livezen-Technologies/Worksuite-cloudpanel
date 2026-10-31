<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * PNET-7: the admin API answers only from ADMIN_ALLOWED_CIDRS (for example the
 * private network plus a break-glass address). Empty means no restriction. The
 * customer panel, your own account, jobs, agents and webhooks are never limited.
 */
class AdminNetwork
{
    private const OPEN = ['app.', 'me.', 'auth.', 'jobs.'];

    public function handle(Request $request, Closure $next)
    {
        $allowed = (array) config('security.admin_allowed_cidrs', []);
        $name = (string) $request->route()?->getName();
        if (! $allowed || array_filter(self::OPEN, fn ($p) => str_starts_with($name, $p))) {
            return $next($request);
        }
        if (! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return response()->json(['error' => ['code' => 'network_restricted', 'message' => 'The admin panel only answers from the private network. Connect your VPN and try again.', 'fields' => (object) []]], 403);
        }

        return $next($request);
    }
}
