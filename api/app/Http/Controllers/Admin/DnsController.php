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
            'name' => 'required|string|max:100', 'type' => 'required|in:cloudflare,powerdns',
            'token' => 'required_if:type,cloudflare|nullable|string|max:255', 'account_id' => 'nullable|string|max:64',
            'api_url' => 'required_if:type,powerdns|nullable|url:http,https|max:255', 'api_key' => 'required_if:type,powerdns|nullable|string|max:255',
            'server_id' => 'nullable|string|max:64|regex:/^[A-Za-z0-9._-]+$/',
            'nameservers' => 'required_if:type,powerdns|array|max:13', 'nameservers.*' => ['string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i'],
        ]);
        $credentials = $data['type'] === 'cloudflare'
            ? ['token' => $data['token'], 'account_id' => $data['account_id'] ?? null]
            : ['api_url' => $data['api_url'], 'api_key' => $data['api_key'], 'server_id' => $data['server_id'] ?? 'localhost',
                'nameservers' => array_values(array_map(fn ($n) => strtolower(rtrim($n, '.')), $data['nameservers']))];
        $account = $this->dns->connect($data['name'], $data['type'], array_filter($credentials, fn ($v) => $v !== null));
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

    /** DNS-009 */
    public function dnssec(string $id)
    {
        return $this->data($this->dns->dnssec(DnsZone::findOrFail($id)));
    }

    public function setDnssec(Request $request, string $id)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->dns->setDnssec(DnsZone::findOrFail($id), (bool) $data['enabled']));
    }

    /** DNS-010 */
    public function settings(string $id)
    {
        return $this->data($this->dns->settings(DnsZone::findOrFail($id)));
    }

    public function updateSettings(Request $request, string $id)
    {
        $data = $request->validate([
            'ssl' => 'sometimes|in:'.implode(',', DnsZoneService::SETTINGS['ssl']),
            'always_use_https' => 'sometimes|boolean',
            'min_tls_version' => 'sometimes|in:'.implode(',', DnsZoneService::SETTINGS['min_tls_version']),
            'cache_level' => 'sometimes|in:'.implode(',', DnsZoneService::SETTINGS['cache_level']),
        ]);
        if (isset($data['always_use_https'])) {
            $data['always_use_https'] = (bool) $data['always_use_https'];
        }
        $request->attributes->set('audit_skip', true);

        return $this->data($this->dns->updateSettings(DnsZone::findOrFail($id), $data));
    }

    /** DNS-011: the zone as a BIND file. */
    public function export(Request $request, string $id)
    {
        $zone = DnsZone::findOrFail($id);
        $file = $this->dns->exportBind($zone);
        if ($request->wantsJson()) {
            return $this->data(['filename' => $zone->name.'.zone', 'content' => $file]);
        }

        return response($file, 200, [
            'Content-Type' => 'text/dns; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$zone->name.'.zone"',
        ]);
    }

    /** DNS-011: adds a BIND file's records; dry_run only reports what would change. */
    public function import(Request $request, string $id)
    {
        $data = $request->validate(['zone_file' => 'required|string|max:1048576', 'dry_run' => 'sometimes|boolean']);
        $request->attributes->set('audit_skip', true);

        return $this->data($this->dns->importBind(DnsZone::findOrFail($id), $data['zone_file'], (bool) ($data['dry_run'] ?? false)));
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
            'dnssec' => ['status' => $z->dnssec_status ?? 'disabled', 'ds' => $z->ds_records ?? []],
            'supports_proxy' => $z->provider?->type === 'cloudflare',
            'supports_settings' => $z->provider?->type === 'cloudflare',
        ];
    }

    private function record(DnsRecord $r): array
    {
        return ['id' => $r->id, 'type' => $r->type, 'name' => $r->name, 'content' => $r->content, 'ttl' => $r->ttl, 'priority' => $r->priority, 'proxied' => $r->proxied];
    }
}
