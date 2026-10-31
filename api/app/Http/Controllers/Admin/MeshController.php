<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\VpnPeer;
use App\Services\MeshService;
use Illuminate\Http\Request;

/** PNET-1..5: the private network between servers and admin devices. */
class MeshController extends Controller
{
    public function __construct(private MeshService $mesh) {}

    public function show()
    {
        return $this->data($this->mesh->overview());
    }

    public function join(string $id)
    {
        $this->mesh->join(Server::findOrFail($id));

        return $this->data($this->mesh->overview(), 202);
    }

    public function leave(string $id)
    {
        $this->mesh->leave(Server::whereNotNull('mesh_ip')->findOrFail($id));

        return $this->data($this->mesh->overview(), 202);
    }

    public function apply()
    {
        $job = $this->mesh->applyAll();

        return $this->data(['job_id' => $job?->id], 202);
    }

    public function addPeer(Request $request)
    {
        $v = $request->validate(['name' => 'required|string|max:60']);
        $r = $this->mesh->addPeer($v['name'], $request->user());

        return $this->data(['id' => $r['peer']->id, 'address' => $r['peer']->address, 'config' => $r['config']], 201);
    }

    public function revokePeer(string $id)
    {
        $this->mesh->revoke(VpnPeer::whereNull('revoked_at')->findOrFail($id));

        return response()->noContent();
    }

    public function lockSsh(Request $request, string $id)
    {
        $v = $request->validate(['allow' => 'sometimes|array|max:20', 'allow.*' => 'string|max:64', 'port' => 'sometimes|integer|between:1,65535']);
        foreach ($v['allow'] ?? [] as $a) {
            [$ip, $bits] = array_pad(explode('/', $a, 2), 2, null);
            if (! filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && ! ctype_digit($bits))) {
                throw \App\Exceptions\ApiException::validation(['allow' => ["{$a} is not an address or CIDR."]]);
            }
        }
        $this->mesh->lockSsh(Server::whereNotNull('mesh_ip')->findOrFail($id), $v['allow'] ?? [], (int) ($v['port'] ?? 22));

        return $this->data($this->mesh->overview(), 202);
    }

    public function confirmSsh(string $id)
    {
        $this->mesh->confirmSsh(Server::findOrFail($id));

        return $this->data($this->mesh->overview());
    }

    public function unlockSsh(string $id)
    {
        $this->mesh->unlockSsh(Server::findOrFail($id));

        return $this->data($this->mesh->overview(), 202);
    }
}
