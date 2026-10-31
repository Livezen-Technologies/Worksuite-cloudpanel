<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every API response (security audit, phase 6). JSON gets a CSP that
 * allows nothing and cannot be framed; HTML the API serves (public pages, invoices, shares)
 * keeps whatever its controller set and may only be framed by the same origin. A header a
 * controller already set is never overwritten.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $json = str_contains((string) $h->get('Content-Type'), 'json');
        $set = fn (string $k, string $v) => $h->has($k) ? null : $h->set($k, $v);

        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $set('X-Frame-Options', $json ? 'DENY' : 'SAMEORIGIN');
        if ($json) {
            $set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        if ($request->isSecure()) {
            $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $h->remove('X-Powered-By');

        return $response;
    }
}
