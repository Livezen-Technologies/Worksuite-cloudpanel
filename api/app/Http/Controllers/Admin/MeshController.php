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
}
