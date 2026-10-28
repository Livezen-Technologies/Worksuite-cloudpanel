<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Services\AgentService;
use App\Services\CommandBus;
use Illuminate\Http\Request;

/** /agent/v1 — see docs/API.md "Agent protocol". */
class AgentController extends Controller
{
    public function __construct(private AgentService $agents) {}

    public function enroll(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|max:255',
            'hostname' => 'nullable|string|max:255',
            'facts' => 'nullable|array',
            'facts.os' => 'nullable|string|max:120', 'facts.kernel' => 'nullable|string|max:120', 'facts.docker_version' => 'nullable|string|max:60',
            'facts.cpu_cores' => 'nullable|integer', 'facts.ram_mb' => 'nullable|integer', 'facts.disk_gb' => 'nullable|integer', 'facts.arch' => 'nullable|string|max:20',
            'csr' => 'nullable|string|max:20000',
        ]);

        return response()->json($this->agents->enroll($data));
    }

    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'agent_version' => 'nullable|string|max:40',
            'stats' => 'nullable|array',
            'containers' => 'nullable|array', 'images' => 'nullable|array', 'networks' => 'nullable|array', 'volumes' => 'nullable|array',
            'docker_error' => 'nullable|string|max:2000',
        ]);
        // Preserve explicit nulls (Docker unreachable) vs. empty lists.
        foreach (['containers', 'images', 'networks', 'volumes'] as $key) {
            $data[$key] = $request->json($key);
        }

        return response()->json($this->agents->heartbeat($request->attributes->get('agent'), $data));
    }

    public function result(Request $request, string $id, CommandBus $bus)
    {
        $data = $request->validate([
            'status' => 'required|in:running,succeeded,failed',
            'progress' => 'nullable|integer|min:0|max:100',
            'message' => 'nullable|string|max:65535',
            'output' => 'nullable',
        ]);
        $agent = $request->attributes->get('agent');
        $command = AgentCommand::allTenants()->where('server_id', $agent->server_id)->findOrFail($id);
        $command = $bus->applyResult($command, $data['status'], $data['progress'] ?? null, $data['message'] ?? null, $request->json('output'));

        return response()->json(['id' => $command->id, 'status' => $command->status]);
    }

    public function installScript()
    {
        $path = config('platform.agent_install_script');
        $stored = config('platform.agent_binary_dir').'/install.sh';
        $file = is_file($stored) ? $stored : (is_file($path) ? $path : null);
        abort_unless($file, 404);

        return response(file_get_contents($file), 200, ['Content-Type' => 'text/x-shellscript; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function download(string $arch)
    {
        $file = config('platform.agent_binary_dir')."/dockermgr-agent-linux-{$arch}";
        abort_unless(is_file($file), 404);

        return response()->download($file, "dockermgr-agent-linux-{$arch}", ['Content-Type' => 'application/octet-stream', 'X-Checksum-Sha256' => hash_file('sha256', $file)]);
    }
}
