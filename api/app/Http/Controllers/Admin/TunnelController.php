<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudflareTunnel;
use App\Models\DnsProviderAccount;
use App\Models\Server;
use App\Services\CloudflareTunnelService;
use Illuminate\Http\Request;

/** CFT-1..6: a server's Cloudflare Tunnel. */
class TunnelController extends Controller
{
    public function __construct(private CloudflareTunnelService $tunnels) {}

    public function show(string $id)
    {
        $server = Server::findOrFail($id);
        $tunnel = CloudflareTunnel::where('server_id', $server->id)->first();

        return $this->data([
            'tunnel' => $tunnel ? $this->tunnels->present($tunnel) : null,
            'hostnames' => $this->tunnels->hostnames($server),
            'providers' => DnsProviderAccount::where('type', 'cloudflare')->get(['id', 'name', 'account_label'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'has_account_id' => ! empty($p->credentials['account_id'])])->values(),
        ]);
    }

    public function enable(Request $request, string $id)
    {
        $v = $request->validate(['dns_provider_id' => 'required|uuid']);
        $tunnel = $this->tunnels->enable(Server::findOrFail($id), DnsProviderAccount::findOrFail($v['dns_provider_id']));

        return $this->data($this->tunnels->present($tunnel), 201);
    }

    public function sync(string $id)
    {
        $tunnel = CloudflareTunnel::where('server_id', Server::findOrFail($id)->id)->firstOrFail();

        return $this->data($this->tunnels->present($this->tunnels->sync($tunnel)));
    }

    public function update(Request $request, string $id)
    {
        $v = $request->validate(['close_ports' => 'required|boolean']);
        $tunnel = CloudflareTunnel::where('server_id', Server::findOrFail($id)->id)->firstOrFail();
        $tunnel->update(['close_ports' => $v['close_ports']]);
        $this->tunnels->lockdown($tunnel);

        return $this->data($this->tunnels->present($tunnel));
    }

    public function restart(string $id)
    {
        $tunnel = CloudflareTunnel::where('server_id', Server::findOrFail($id)->id)->firstOrFail();
        $this->tunnels->runConnector($tunnel);

        return $this->data($this->tunnels->present($tunnel), 202);
    }

    public function disable(string $id)
    {
        $this->tunnels->disable(CloudflareTunnel::where('server_id', Server::findOrFail($id)->id)->firstOrFail());

        return response()->noContent();
    }
}
