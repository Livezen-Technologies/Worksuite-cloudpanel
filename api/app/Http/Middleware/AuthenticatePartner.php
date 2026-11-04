<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\PartnerClient;
use App\Support\PartnerSignature;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Partner API authentication: an HMAC-signed request from a registered partner
 * client, inside the time window, with a nonce never seen before, holding the
 * scope the route asks for (`partner:deployments:write`). POSTs need an
 * Idempotency-Key; a repeat replays the first answer.
 *
 * No user session, no tenant: a partner acts on its own deployments only, and
 * the controller resolves the organisation from the deployment it addresses.
 */
class AuthenticatePartner
{
    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $keyId = (string) $request->header('X-Livezen-Key');
        $ts = (string) $request->header('X-Livezen-Timestamp');
        $nonce = (string) $request->header('X-Livezen-Nonce');
        $sig = (string) $request->header('X-Livezen-Signature');
        if ($keyId === '' || $ts === '' || $nonce === '' || $sig === '') {
            throw new ApiException('unauthenticated', 'Missing partner signature headers.', 401);
        }
        $client = PartnerClient::where('key_id', $keyId)->where('active', true)->first();
        if (! $client) {
            throw new ApiException('unauthenticated', 'Unknown or disabled partner key.', 401);
        }
        $tolerance = (int) config('partner.signature_tolerance', 300);
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > $tolerance) {
            throw new ApiException('unauthenticated', 'Request timestamp is outside the allowed window.', 401);
        }
        if (strlen($nonce) < 16 || strlen($nonce) > 128) {
            throw new ApiException('unauthenticated', 'Invalid nonce.', 401);
        }
        $canonical = PartnerSignature::canonical($ts, $request->method(), $request->getRequestUri(), $request->getContent(), $nonce);
        if (! PartnerSignature::verify($client->secret, $canonical, $sig)) {
            throw new ApiException('unauthenticated', 'Signature does not match.', 401);
        }
        if (! Cache::add("partner-nonce:{$client->id}:{$nonce}", 1, $tolerance * 2)) {
            throw new ApiException('unauthenticated', 'This request was already received (nonce replay).', 401);
        }
        if ($client->allowed_ips && ! in_array($request->ip(), $client->allowed_ips, true)) {
            throw ApiException::forbidden('This partner key may not be used from this address.');
        }
        if ($scope && ! $client->can($scope)) {
            throw ApiException::forbidden("This partner key lacks the {$scope} scope.");
        }
        app(Tenant::class)->set(null);
        $request->attributes->set('partner_client', $client);
        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        if ($request->method() !== 'POST') {
            return $next($request);
        }

        return $this->idempotent($request, $client, $next);
    }

    private function idempotent(Request $request, PartnerClient $client, Closure $next)
    {
        $key = substr((string) $request->header('Idempotency-Key'), 0, 255);
        if ($key === '') {
            throw ApiException::validation(['Idempotency-Key' => ['Partner POST requests need an Idempotency-Key header.']], 'Idempotency-Key header is required.');
        }
        $fingerprint = hash('sha256', $request->path().'|'.$request->getContent());
        DB::table('partner_requests')->where('created_at', '<', now()->subHours(config('platform.idempotency_ttl_hours', 24)))->delete();
        $existing = DB::table('partner_requests')->where('partner_client_id', $client->id)->where('key', $key)->first();
        if ($existing) {
            if ($existing->fingerprint !== $fingerprint) {
                throw ApiException::conflict('This Idempotency-Key was already used for a different request.');
            }
            if ($existing->status === null) {
                throw ApiException::conflict('A request with this Idempotency-Key is still in progress.');
            }

            return response($existing->body, $existing->status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }
        try {
            DB::table('partner_requests')->insert(['partner_client_id' => $client->id, 'key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now()]);
        } catch (\Illuminate\Database\QueryException) {
            throw ApiException::conflict('A request with this Idempotency-Key is still in progress.');
        }
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('partner_requests')->where('partner_client_id', $client->id)->where('key', $key)->delete();
            throw $e;
        }
        if ($response->getStatusCode() >= 500 || $response->getStatusCode() === 422) {
            DB::table('partner_requests')->where('partner_client_id', $client->id)->where('key', $key)->delete();
        } else {
            DB::table('partner_requests')->where('partner_client_id', $client->id)->where('key', $key)
                ->update(['status' => $response->getStatusCode(), 'body' => $response->getContent()]);
        }

        return $response;
    }
}
