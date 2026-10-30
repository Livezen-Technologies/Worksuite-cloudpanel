<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DnsProviderAccount;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\DnsZoneService;
use Illuminate\Http\Request;

/** DNS zone management (SRS 4.18): provider accounts, zones and their records. */
class DnsController extends Controller
{
    public function __construct(private DnsZoneService $dns) {}

    public function providers()
    {
        return $this->data(DnsProviderAccount::orderBy('name')->get()->map(fn ($p) => $this->provider($p)));
    }

    /** DNS-001: the token is verified before it is stored, and never returned. */
    public function connect(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'type' => 'required|in:cloudflare',
            'token' => 'required|string|max:255', 'account_id' => 'nullable|string|max:64',
        ]);
        $account = $this->dns->connect($data['name'], $data['type'], array_filter(['token' => $data['token'], 'account_id' => $data['account_id'] ?? null]));
        $request->attributes->set('audit_skip', true);

        return $this->data($this->provider($account), 201);
    }

    public function disconnect(Request $request, string $id)
    {
        $account = DnsProviderAccount::findOrFail($id);
        abort_if(DnsZone::where('dns_provider_id', $account->id)->exists(), 409, 'Remove this provider\'s zones from the panel first.');
        $account->delete();

        return response()->noContent();
    }

    /** DNS-002 */
    public function index()
    {
        return $this->data(DnsZone::with('provider:id,name,type')->orderBy('name')->get()->map(fn ($z) => $this->zone($z)));
    }

    /** DNS-003: empty, or with the platform's default records pointing at an address. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'dns_provider_id' => 'required|uuid', 'name' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i'],
            'defaults_to' => 'nullable|ipv4',
        ]);
        $zone = $this->dns->createZone(DnsProviderAccount::findOrFail($data['dns_provider_id']), $data['name'], $data['defaults_to'] ?? null);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->zone($zone), 201);
    }

    public function show(string $id)
    {
        $zone = DnsZone::with('provider:id,name,type')->findOrFail($id);

        return $this->data($this->zone($zone) + [
            'records' => $zone->records()->orderBy('type')->orderBy('name')->get()->map(fn ($r) => $this->record($r)),
        ]);
    }

    /** Removes the zone from the panel only; the zone stays at the provider. */
    public function destroy(string $id)
    {
        DnsZone::findOrFail($id)->delete();

        return response()->noContent();
    }

    /** DNS-004 */
    public function checkNameservers(string $id)
    {
        return $this->data($this->dns->checkNameservers(DnsZone::findOrFail($id)));
    }

    /** DNS-012 */
    public function sync(string $id)
    {
        return $this->data($this->dns->sync(DnsZone::findOrFail($id)));
    }

    public function storeRecord(Request $request, string $id)
    {
        $request->attributes->set('audit_skip', true);

        return $this->data($this->record($this->dns->createRecord(DnsZone::findOrFail($id), $this->input($request))), 201);
    }

    public function updateRecord(Request $request, string $id, string $recordId)
    {
        $zone = DnsZone::findOrFail($id);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->record($this->dns->updateRecord($zone, $zone->records()->findOrFail($recordId), $this->input($request))));
    }

    public function destroyRecord(Request $request, string $id, string $recordId)
    {
        $zone = DnsZone::findOrFail($id);
        $this->dns->deleteRecord($zone, $zone->records()->findOrFail($recordId));
        $request->attributes->set('audit_skip', true);

        return response()->noContent();
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'type' => 'sometimes|string|max:10', 'name' => 'nullable|string|max:253', 'content' => 'required|string|max:2048',
            'ttl' => 'sometimes|integer', 'priority' => 'nullable|integer|min:0|max:65535', 'proxied' => 'sometimes|boolean',
        ]);
    }

    private function provider(DnsProviderAccount $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'account' => $p->account_label, 'created_at' => $p->created_at?->toIso8601ZuluString()];
    }

    private function zone(DnsZone $z): array
    {
        return [
            'id' => $z->id, 'name' => $z->name, 'status' => $z->status, 'nameservers' => $z->nameservers ?? [],
            'records_count' => $z->records_count, 'provider' => $z->provider ? ['id' => $z->provider->id, 'name' => $z->provider->name, 'type' => $z->provider->type] : null,
            'last_synced_at' => $z->last_synced_at?->toIso8601ZuluString(),
        ];
    }

    private function record(DnsRecord $r): array
    {
        return ['id' => $r->id, 'type' => $r->type, 'name' => $r->name, 'content' => $r->content, 'ttl' => $r->ttl, 'priority' => $r->priority, 'proxied' => $r->proxied];
    }
}
