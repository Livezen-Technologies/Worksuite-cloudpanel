<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Server;
use App\Services\ServerFirewallService;
use Illuminate\Http\Request;

/** Server → Firewall: the host firewall (nftables) for one server. */
class ServerFirewallController extends Controller
{
    public function __construct(private ServerFirewallService $svc) {}

    public function show(string $id)
    {
        $server = Server::findOrFail($id);
        $fw = $this->svc->for($server);

        return $this->data($fw->toArray() + ['never_block' => $this->svc->neverBlock(), 'online' => $server->isOnline()]);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate([
            'mode' => 'sometimes|in:block,allow_only',
            'block' => 'sometimes|array|max:5000', 'block.*' => 'string|max:64',
            'allow' => 'sometimes|array|max:5000', 'allow.*' => 'string|max:64',
            'ports' => 'sometimes|array|max:100', 'ports.*' => 'integer|min:1|max:65535',
        ]);
        $fw = $this->svc->save($this->svc->for(Server::findOrFail($id)), $data);

        return $this->data($fw->toArray() + ['never_block' => $this->svc->neverBlock()]);
    }

    public function preview(Request $request, string $id)
    {
        return $this->accepted($this->svc->apply(Server::findOrFail($id), $request->user()?->id, true));
    }

    public function apply(Request $request, string $id)
    {
        return $this->accepted($this->svc->apply(Server::findOrFail($id), $request->user()?->id));
    }

    public function disable(Request $request, string $id)
    {
        return $this->accepted($this->svc->apply(Server::findOrFail($id), $request->user()?->id, false, true));
    }
}
