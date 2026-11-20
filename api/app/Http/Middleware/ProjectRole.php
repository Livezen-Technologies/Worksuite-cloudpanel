<?php

namespace App\Http\Middleware;

use App\Services\ProjectGate;
use Closure;
use Illuminate\Http\Request;

/**
 * PWS-B: project roles enforced on every project, app, container, deployment, environment and
 * backup endpoint (admin and /app). Runs after the organisation RBAC (perm:) and only narrows it:
 * a request that names a resource inside a project is checked against the caller's project role.
 */
class ProjectRole
{
    public function __construct(private ProjectGate $gate) {}

    public function handle(Request $request, Closure $next)
    {
        $this->gate->check($request);

        return $next($request);
    }
}
