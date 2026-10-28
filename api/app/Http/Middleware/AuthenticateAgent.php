<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;

/** Agent transport auth: Bearer agent_secret (stored as sha256) plus the mTLS client certificate check. */
class AuthenticateAgent
{
    public function __construct(private Tenant $tenant) {}

    public function handle(Request $request, Closure $next)
    {
        $secret = $request->bearerToken();
        $agent = $secret ? Agent::allTenants()->with(['server' => fn ($q) => $q->withoutGlobalScope('organization')])
            ->where('secret_hash', hash('sha256', $secret))->first() : null;
        if (! $agent || ! $agent->server) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Unknown agent credentials.', 'fields' => (object) [], 'request_id' => $request->attributes->get('request_id')]], 401);
        }
        // SEC-003: AGENT_MTLS=header|required checks the client certificate the proxy forwarded.
        app(\App\Services\AgentCertificateService::class)->enforce($request, $agent);
        $this->tenant->set($agent->organization_id);
        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
