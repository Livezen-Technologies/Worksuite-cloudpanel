<?php

namespace App\Http\Controllers\Relay;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\AgentCertificateService;
use App\Services\TerminalService;
use Illuminate\Http\Request;

/** Internal API for agent/cmd/relay (X-Relay-Secret). Not part of the public contract. */
class RelayController extends Controller
{
    public function __construct(private TerminalService $terminals) {}

    /** The relay forwards the agent's Bearer secret (and proxy mTLS headers) for its WebSocket connections. */
    public function verifyAgent(Request $request, AgentCertificateService $certs)
    {
        $data = $request->validate(['agent_secret' => 'required|string|max:255']);
        $agent = Agent::allTenants()->where('secret_hash', hash('sha256', $data['agent_secret']))->first();
        if (! $agent) {
            throw new ApiException('unauthenticated', 'Unknown agent credentials.', 401);
        }
        $certs->enforce($request, $agent);

        return response()->json(['data' => ['server_id' => $agent->server_id, 'organization_id' => $agent->organization_id]]);
    }

    public function connect(Request $request, string $id)
    {
        $data = $request->validate(['token' => 'required|string|max:255', 'cols' => 'nullable|integer', 'rows' => 'nullable|integer', 'ip' => 'nullable|string|max:64']);

        return response()->json(['data' => $this->terminals->connect($id, $data['token'], (int) ($data['cols'] ?? 80), (int) ($data['rows'] ?? 24), $data['ip'] ?? null)]);
    }

    public function status(string $id)
    {
        return response()->json(['data' => $this->terminals->status($id)]);
    }

    public function closed(Request $request, string $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $session = $this->terminals->closed($id, $data['reason'] ?? 'closed');

        return response()->json(['data' => ['id' => $session->id, 'ended_at' => $session->ended_at?->toIso8601ZuluString()]]);
    }

    public function recording(Request $request, string $id)
    {
        $session = $this->terminals->storeRecording($id, (string) $request->getContent());

        return response()->json(['data' => ['id' => $session->id, 'bytes' => $session->recording_bytes]]);
    }
}
