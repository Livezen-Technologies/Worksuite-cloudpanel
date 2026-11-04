<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FirewallCountry;
use App\Models\FirewallEvent;
use App\Models\FirewallIpList;
use App\Models\FirewallProtectedPath;
use App\Models\FirewallRule;
use App\Services\FirewallConfigService;
use App\Services\TraefikConfigService;
use App\Exceptions\ApiException;
use App\Support\Lists;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * FW-001. Everything on the Firewall screen that is not a rate-limit rule:
 * the country policy, challenge-protected paths, the event log, and a summary
 * saying plainly which rules the proxy can actually enforce.
 */
class FirewallController extends Controller
{
    public function __construct(private TraefikConfigService $traefik, private FirewallConfigService $firewall) {}

    /** Settings, country policy, protected paths and what is unenforceable. */
    public function show()
    {
        $org = Tenant::current();
        $settings = array_replace_recursive(config('platform.default_settings.firewall'), $org->settings['firewall'] ?? []);
        $hasSecret = ! empty($settings['secret_key_encrypted']);
        unset($settings['secret_key_encrypted']);

        return $this->data([
            'settings' => $settings + ['secret_key_set' => $hasSecret],
            'countries' => FirewallCountry::get(['id', 'code', 'state', 'domain_id'])->toArray(),
            'paths' => FirewallProtectedPath::with('domain:id,name')->get()
                ->map(fn (FirewallProtectedPath $p) => [
                    'id' => $p->id, 'path' => $p->path, 'enabled' => $p->enabled,
                    'domain_id' => $p->domain_id, 'scope' => $p->domain?->name ?? 'all domains',
                ])->all(),
            'ip_lists' => FirewallIpList::with('domain:id,name')->orderBy('list')->orderBy('cidr')->get()
                ->map(fn (FirewallIpList $e) => $this->entry($e))->all(),
            'your_ip' => request()->ip(),
            'rules_total' => FirewallRule::count(),
            // Stated rather than implied: these actions have no enforcement path
            // today, so the screen must not suggest they are in force.
            'unsupported' => $this->firewall->unsupported(),
            'country_enforcement' => \App\Services\CrowdSecConfigService::enabled() ? 'crowdsec' : 'unavailable',
            'challenge_enforcement' => \App\Services\CrowdSecConfigService::enabled() ? 'crowdsec' : 'unavailable',
        ]);
    }

    /** Replaces the country policy wholesale, the way the tab edits it. */
    public function countries(Request $request)
    {
        $data = $request->validate([
            'countries' => 'present|array',
            'countries.*.code' => 'required|string|size:2',
            'countries.*.state' => 'required|in:block,challenge',
            'countries.*.domain_id' => 'nullable|uuid|exists:domains,id',
        ]);
        FirewallCountry::query()->delete();
        foreach ($data['countries'] as $c) {
            FirewallCountry::create([
                'code' => strtoupper($c['code']),
                'state' => $c['state'],
                'domain_id' => $c['domain_id'] ?? null,
            ]);
        }
        $this->traefik->push();

        return $this->show();
    }

    public function storePath(Request $request)
    {
        $data = $request->validate([
            'path' => 'required|string|max:255',
            'domain_id' => 'nullable|uuid|exists:domains,id',
            'enabled' => 'sometimes|boolean',
        ]);
        $path = FirewallProtectedPath::create($data);
        $this->traefik->push();

        // fresh(): 'enabled' defaults in the database, so the created instance
        // would report it as null rather than true.
        $path = $path->fresh();

        return $this->data(['id' => $path->id, 'path' => $path->path, 'enabled' => $path->enabled], 201);
    }

    public function updatePath(Request $request, string $id)
    {
        $path = FirewallProtectedPath::findOrFail($id);
        $path->update($request->validate(['enabled' => 'sometimes|boolean', 'path' => 'sometimes|string|max:255']));
        $this->traefik->push();
        $path = $path->fresh();

        return $this->data(['id' => $path->id, 'path' => $path->path, 'enabled' => $path->enabled]);
    }

    public function destroyPath(string $id)
    {
        FirewallProtectedPath::findOrFail($id)->delete();
        $this->traefik->push();

        return response()->noContent();
    }

    /** The Events tab. */
    public function events(Request $request)
    {
        return response()->json(Lists::paginate(
            FirewallEvent::query()->with('domain:id,name')->orderByDesc('occurred_at'),
            $request,
            ['ip', 'path', 'rule_name'],
            ['occurred_at', 'action', 'ip'],
            ['action', 'country', 'domain_id', 'firewall_rule_id'],
            fn (FirewallEvent $e) => [
                'id' => $e->id,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'action' => $e->action,
                'ip' => $e->ip,
                'country' => $e->country,
                'request' => trim(($e->method ? $e->method.' ' : '').($e->path ?? '')),
                'rule' => $e->rule_name,
                'domain' => $e->domain?->name,
            ],
        ));
    }

    /** FW-010. One switch: every visitor on the scope (all domains or one) gets a challenge. */
    public function underAttack(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'domain_id' => 'nullable|uuid|exists:domains,id',
        ]);
        $org = Tenant::current();
        $settings = $org->settings ?? [];
        $wasOn = ! empty($settings['firewall']['under_attack']);
        $settings['firewall'] = array_merge($settings['firewall'] ?? [], [
            'under_attack' => $data['enabled'],
            'under_attack_domain_id' => $data['domain_id'] ?? null,
        ]);
        $org->forceFill(['settings' => $settings])->save();
        $this->traefik->push();

        // EML: tell the customer their site is protected, and staff that a site is under attack.
        if ($data['enabled'] && ! $wasOn) {
            $site = ! empty($data['domain_id']) ? (string) \App\Models\Domain::allTenants()->find($data['domain_id'])?->name : 'all your sites';
            $vars = ['site' => $site, 'started' => now()->format('j M Y, H:i').' UTC', 'mode' => 'Browser check for every visitor (CrowdSec)'];
            $mail = app(\App\Services\EmailTemplateService::class);
            $mail->notifyOrganization($org->id, 'under_attack', $vars, 'on:'.($data['domain_id'] ?? 'all'), 30);
            $mail->notifyStaff('under_attack_admin', $vars + ['customer' => (string) $org->name, 'by' => (string) ($request->user()?->email ?? 'automation')], $org->id.':'.($data['domain_id'] ?? 'all'), 30);
        }

        return $this->show();
    }

    /**
     * FW-011. Adds an address or range to the allow or block list. FW-014: a block
     * entry covering the caller's own address needs confirm=true.
     */
    public function storeIp(Request $request)
    {
        $data = $request->validate([
            'list' => 'required|in:allow,block',
            'cidr' => 'required|string|max:64',
            'note' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date|after:now',
            'domain_id' => 'nullable|uuid|exists:domains,id',
            'confirm' => 'sometimes|boolean',
        ]);
        $cidr = FirewallIpList::normalise($data['cidr']);
        if (! $cidr) {
            throw ApiException::validation(['cidr' => ['Enter an IP address or a CIDR range, such as 203.0.113.0/24.']]);
        }
        $this->guardSelf($data['list'], $cidr, (bool) ($data['confirm'] ?? false));
        if (FirewallIpList::where('list', $data['list'])->where('cidr', $cidr)->exists()) {
            throw ApiException::conflict("{$cidr} is already on the {$data['list']} list.");
        }
        $entry = FirewallIpList::create([
            'list' => $data['list'], 'cidr' => $cidr, 'note' => $data['note'] ?? null,
            'expires_at' => $data['expires_at'] ?? null, 'domain_id' => $data['domain_id'] ?? null,
        ]);
        $this->traefik->push();

        return $this->data($this->entry($entry->load('domain:id,name')), 201);
    }

    public function updateIp(Request $request, string $id)
    {
        $entry = FirewallIpList::findOrFail($id);
        $data = $request->validate([
            'note' => 'sometimes|nullable|string|max:255',
            'expires_at' => 'sometimes|nullable|date|after:now',
        ]);
        $entry->update($data);
        $this->traefik->push();

        return $this->data($this->entry($entry->fresh()->load('domain:id,name')));
    }

    public function destroyIp(string $id)
    {
        FirewallIpList::findOrFail($id)->delete();
        $this->traefik->push();

        return response()->noContent();
    }

    /** FW-012. The filtered event log as CSV (in JSON, as the client parses every response). */
    public function exportEvents(Request $request)
    {
        $q = FirewallEvent::query()->with('domain:id,name')->orderByDesc('occurred_at');
        foreach (['action', 'country', 'domain_id', 'firewall_rule_id'] as $f) {
            $request->filled($f) && $q->where($f, $request->input($f));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $q->where(fn ($q) => $q->where('ip', 'like', $term)->orWhere('path', 'like', $term)->orWhere('rule_name', 'like', $term));
        }
        $rows = [['time', 'action', 'ip', 'country', 'request', 'rule', 'domain']];
        foreach ($q->limit(10000)->get() as $e) {
            $rows[] = [$e->occurred_at?->toIso8601String(), $e->action, $e->ip, $e->country,
                trim(($e->method ? $e->method.' ' : '').($e->path ?? '')), $e->rule_name, $e->domain?->name];
        }
        $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => $this->csvCell((string) $v), $r)), $rows))."\n";

        return $this->data(['filename' => 'firewall-events-'.now()->format('Ymd-His').'.csv', 'csv' => $csv]);
    }

    /** FW-014. Refuses, unless confirmed, a block that would lock out the admin making it. */
    private function guardSelf(string $list, string $cidr, bool $confirm): void
    {
        $ip = request()->ip();
        if ($list === 'block' && ! $confirm && $ip && FirewallIpList::contains($cidr, $ip)) {
            throw ApiException::conflict("{$cidr} includes your own address ({$ip}). Send confirm to block it anyway.");
        }
    }

    private function csvCell(string $v): string
    {
        // A leading =, +, - or @ would run as a formula in a spreadsheet.
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
            $v = "'".$v;
        }

        return preg_match('/[",\n]/', $v) ? '"'.str_replace('"', '""', $v).'"' : $v;
    }

    private function entry(FirewallIpList $e): array
    {
        return [
            'id' => $e->id, 'list' => $e->list, 'cidr' => $e->cidr, 'note' => $e->note,
            'expires_at' => $e->expires_at?->toIso8601String(),
            'expired' => $e->expires_at !== null && $e->expires_at->isPast(),
            'domain_id' => $e->domain_id, 'scope' => $e->domain?->name ?? 'all domains',
        ];
    }
}
