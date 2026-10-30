<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FirewallCountry;
use App\Models\FirewallEvent;
use App\Models\FirewallProtectedPath;
use App\Models\FirewallRule;
use App\Services\FirewallConfigService;
use App\Services\TraefikConfigService;
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
}
