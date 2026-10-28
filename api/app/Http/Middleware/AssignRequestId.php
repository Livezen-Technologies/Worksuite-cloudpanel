<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssignRequestId
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->headers->get('X-Request-Id');
        $id = is_string($id) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) ? $id : (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
