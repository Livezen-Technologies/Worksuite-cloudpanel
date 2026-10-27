<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** /internal/relay/*: only the terminal relay, authenticated with the shared RELAY_SECRET. */
class VerifyRelaySecret
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('security.relay.secret');
        $given = (string) $request->header('X-Relay-Secret');
        if ($secret === '' || strlen($secret) < 16) {
            return response()->json(['error' => ['code' => 'service_unavailable', 'message' => 'The terminal relay is not configured (RELAY_SECRET).', 'fields' => (object) [], 'request_id' => $request->attributes->get('request_id')]], 503);
        }
        if (! hash_equals($secret, $given)) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Invalid relay credentials.', 'fields' => (object) [], 'request_id' => $request->attributes->get('request_id')]], 401);
        }

        return $next($request);
    }
}
