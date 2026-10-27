<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key on POST: same key + same user within 24 h replays the first
 * response. Reusing a key for a different request is a 409 conflict.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();
        if (! $key || ! $user || $request->method() !== 'POST') {
            return $next($request);
        }
        $key = substr($key, 0, 255);
        $fingerprint = hash('sha256', $request->path().'|'.$request->getContent());
        $since = now()->subHours(config('platform.idempotency_ttl_hours'));
        DB::table('idempotency_keys')->where('user_id', $user->id)->where('key', $key)->where('created_at', '<', $since)->delete();

        $existing = DB::table('idempotency_keys')->where('user_id', $user->id)->where('key', $key)->first();
        if ($existing) {
            if ($existing->fingerprint !== $fingerprint) {
                return $this->error($request, 'This Idempotency-Key was already used for a different request.');
            }
            if ($existing->status === null) {
                return $this->error($request, 'A request with this Idempotency-Key is still in progress.');
            }

            return response($existing->body, $existing->status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }

        try {
            DB::table('idempotency_keys')->insert(['user_id' => $user->id, 'key' => $key, 'method' => 'POST', 'path' => $request->path(), 'fingerprint' => $fingerprint, 'created_at' => now()]);
        } catch (\Illuminate\Database\QueryException) {
            return $this->error($request, 'A request with this Idempotency-Key is still in progress.');
        }

        /** @var Response $response */
        $response = $next($request);
        if ($response->getStatusCode() >= 500) {
            DB::table('idempotency_keys')->where('user_id', $user->id)->where('key', $key)->delete();
        } else {
            DB::table('idempotency_keys')->where('user_id', $user->id)->where('key', $key)
                ->update(['status' => $response->getStatusCode(), 'body' => $response->getContent()]);
        }

        return $response;
    }

    private function error(Request $request, string $message)
    {
        return response()->json(['error' => ['code' => 'conflict', 'message' => $message, 'fields' => (object) [], 'request_id' => $request->attributes->get('request_id')]], 409);
    }
}
