<?php

namespace App\Http\Middleware;

use App\Services\Authorizer;
use Closure;
use Illuminate\Http\Request;

/** Route-level RBAC: perm:servers.manage (any of several with "|"). */
class RequirePermission
{
    public function __construct(private Authorizer $authorizer) {}

    public function handle(Request $request, Closure $next, string $permissions)
    {
        $write = ! $request->isMethodSafe();
        $last = null;
        foreach (explode('|', $permissions) as $permission) {
            try {
                $this->authorizer->authorize($request->user(), $permission, null, $write);

                return $next($request);
            } catch (\App\Exceptions\ApiException $e) {
                $last = $e;
            }
        }
        throw $last;
    }
}
