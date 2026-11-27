<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\VpnNetwork;
use App\Services\AuditLogger;
use App\Support\Cidr;
use App\Support\Lists;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * VPN phase 1: client-access VPN networks. Creating, editing and deleting are records only; deploying
 * to the gateway is a later phase. Address ranges must be private, must not overlap each other, the
 * server mesh, or any Docker network the panel knows of.
 */
class VpnNetworkController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Lists::paginate(VpnNetwork::with('gateway:id,name,ip,status'), $request, ['name', 'cidr'], ['name', 'created_at'], ['protocol', 'status', 'gateway_server_id'],
            fn (VpnNetwork $n) => collect($n->toArray())->except('gateway')->all() + ['gateway_name' => $n->gateway?->name]));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $net = VpnNetwork::create($data + ['status' => 'draft']);
        app(AuditLogger::class)->log('vpn.network.create', 'vpn_networks', $net->id, ['name' => $net->name, 'protocol' => $net->protocol, 'cidr' => $net->cidr]);

        return $this->data($net->fresh(), 201);
    }

    public function update(Request $request, string $id)
    {
        $net = VpnNetwork::findOrFail($id);
        if (in_array($net->status, ['active', 'deploying'], true) && $request->hasAny(['protocol', 'gateway_server_id', 'cidr', 'port', 'transport'])) {
            throw ValidationException::withMessages(['status' => 'Stop the network before changing its protocol, gateway, range or port.']);
        }
        $data = $this->validated($request, $net);
        $net->update($data);
        app(AuditLogger::class)->log('vpn.network.update', 'vpn_networks', $net->id, array_keys($data));

        return $this->data($net->fresh());
    }

    public function destroy(string $id)
    {
        $net = VpnNetwork::findOrFail($id);
        if (! in_array($net->status, ['draft', 'failed', 'stopped'], true)) {
            throw ValidationException::withMessages(['status' => 'Stop the network on its gateway before deleting it.']);
        }
        app(AuditLogger::class)->log('vpn.network.delete', 'vpn_networks', $net->id, ['name' => $net->name]);
        $net->delete();

        return response()->noContent();
    }

    public function deploy(Request $request, string $id, \App\Services\VpnService $vpn)
    {
        $net = VpnNetwork::findOrFail($id);
        $job = $vpn->deploy($net, $request->user()?->id);
        app(AuditLogger::class)->log('vpn.network.deploy', 'vpn_networks', $net->id, ['name' => $net->name, 'gateway' => $net->gateway_server_id]);

        return $this->data(['job_id' => $job->id, 'network' => $net->fresh()], 202);
    }

    public function stop(Request $request, string $id, \App\Services\VpnService $vpn)
    {
        $net = VpnNetwork::findOrFail($id);
        $job = $vpn->stop($net, $request->user()?->id);
        app(AuditLogger::class)->log('vpn.network.stop', 'vpn_networks', $net->id, ['name' => $net->name]);

        return $this->data(['job_id' => $job?->id, 'network' => $net->fresh()], 202);
    }

    public function status(string $id, \App\Services\VpnService $vpn)
    {
        $net = VpnNetwork::findOrFail($id);
        $job = $vpn->refresh($net);

        return $this->data(['job_id' => $job?->id, 'network' => $net->fresh()], $job ? 202 : 200);
    }

    /** Free address ranges and default ports, for the wizard. */
    public function suggest()
    {
        $taken = $this->takenRanges(null);
        $cidr = null;
        for ($i = 0; $i < 256 && ! $cidr; $i++) {
            $try = "10.90.$i.0/24";
            if (! collect($taken)->contains(fn ($t) => Cidr::overlaps($try, $t))) {
                $cidr = $try;
            }
        }

        return $this->data(['cidr' => $cidr, 'ports' => ['wireguard' => 51821, 'openvpn' => 1194], 'mesh' => config('platform.mesh.subnet', '10.88.0.0/16')]);
    }

    private function validated(Request $request, ?VpnNetwork $net): array
    {
        $req = $net ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:80', Rule::unique('vpn_networks')->where('organization_id', app(\App\Support\Tenant::class)->id())->ignore($net?->id)],
            'protocol' => [$req, Rule::in(['wireguard', 'openvpn'])],
            'gateway_server_id' => [$req, 'uuid'],
            'cidr' => [$req, 'string', 'max:43'],
            'port' => [$req, 'integer', 'between:1,65535'],
            'transport' => ['sometimes', Rule::in(['udp', 'tcp'])],
            'routing' => ['sometimes', Rule::in(['split', 'full'])],
            'private_dns' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['gateway_server_id'])) {
            Server::findOrFail($data['gateway_server_id']);
        }
        $protocol = $data['protocol'] ?? $net?->protocol;
        if ($protocol === 'wireguard') {
            $data['transport'] = 'udp'; // WireGuard is UDP only
        }
        if (isset($data['cidr'])) {
            $cidr = trim($data['cidr']);
            $r = Cidr::range($cidr);
            if (! $r) {
                throw ValidationException::withMessages(['cidr' => 'Use an IPv4 range like 10.90.0.0/24 (prefix /8 to /30).']);
            }
            if (! Cidr::isNetworkAddress($cidr)) {
                throw ValidationException::withMessages(['cidr' => 'Start the range on its network address, e.g. '.long2ip($r[0]).'/'.explode('/', $cidr)[1].'.']);
            }
            if (! Cidr::isPrivate($cidr)) {
                throw ValidationException::withMessages(['cidr' => 'Use a private range (10.x, 172.16–31.x, 192.168.x or 100.64/10).']);
            }
            foreach ($this->takenRanges($net?->id) as $label => $t) {
                if (Cidr::overlaps($cidr, $t)) {
                    throw ValidationException::withMessages(['cidr' => "Overlaps $label ($t)."]);
                }
            }
            $data['cidr'] = $cidr;
        }
        $gateway = $data['gateway_server_id'] ?? $net?->gateway_server_id;
        $port = $data['port'] ?? $net?->port;
        $transport = $data['transport'] ?? $net?->transport ?? 'udp';
        if ($gateway && $port) {
            $clash = VpnNetwork::where('gateway_server_id', $gateway)->where('port', $port)->where('transport', $transport)
                ->when($net, fn ($q) => $q->where('id', '!=', $net->id))->value('name');
            if ($clash) {
                throw ValidationException::withMessages(['port' => "$transport/$port is already used by $clash on that gateway."]);
            }
            $meshPort = (int) config('platform.mesh.port', 51820);
            if ($transport === 'udp' && (int) $port === $meshPort) {
                throw ValidationException::withMessages(['port' => "UDP $meshPort is the server mesh port."]);
            }
        }

        return $data;
    }

    /** @return array<string,string> label => cidr of ranges a VPN network must not overlap */
    private function takenRanges(?string $exceptId): array
    {
        $taken = ['the server mesh' => (string) config('platform.mesh.subnet', '10.88.0.0/16'), 'the mesh container range' => (string) config('platform.mesh.container_supernet', '10.89.0.0/16')];
        foreach (VpnNetwork::allTenants()->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->get(['name', 'cidr']) as $n) {
            $taken['VPN network '.$n->name] = $n->cidr;
        }
        foreach (\App\Models\Network::allTenants()->whereNotNull('subnet')->get(['name', 'subnet']) as $n) {
            if (Cidr::range((string) $n->subnet)) {
                $taken['Docker network '.$n->name] ??= (string) $n->subnet;
            }
        }

        return $taken;
    }
}
