<?php

namespace App\Http\Controllers\Customer;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\FirewallCountry;
use App\Models\FirewallProtectedPath;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Services\CrowdSecConfigService;
use App\Services\TraefikConfigService;
use Illuminate\Http\Request;

/**
 * FW-015: customers protect their own domains: challenge-protected paths (reCAPTCHA /
 * Turnstile) and country rules, each per domain and within the plan's limits
 * (firewall_paths, firewall_countries; 0 turns the feature off for a plan).
 * Organisation-wide rules set by admins are not shown or changed here.
 */
class FirewallController extends Controller
{
    public function __construct(private BillingService $billing, private TraefikConfigService $traefik, private AuditLogger $audit) {}

    private function limits(): array
    {
        return ['paths' => $this->billing->limit('firewall_paths', 5), 'countries' => $this->billing->limit('firewall_countries', 10)];
    }

    private function domain(string $id): Domain
    {
        return Domain::query()->where('platform_subdomain', false)->findOrFail($id);
    }

    public function index()
    {
        $domains = Domain::query()->where('platform_subdomain', false)->orderBy('name')->get(['id', 'name']);
        $ids = $domains->pluck('id');
        $paths = FirewallProtectedPath::query()->whereIn('domain_id', $ids)->orderBy('path')->get();
        $countries = FirewallCountry::query()->whereIn('domain_id', $ids)->orderBy('code')->get();

        return $this->data([
            'enforced' => CrowdSecConfigService::enabled(),
            'limits' => $this->limits(),
            'used' => ['paths' => $paths->count(), 'countries' => $countries->count()],
            'domains' => $domains->map(fn (Domain $d) => [
                'id' => $d->id, 'name' => $d->name,
                'paths' => $paths->where('domain_id', $d->id)->values()->map(fn ($p) => ['id' => $p->id, 'path' => $p->path, 'enabled' => (bool) $p->enabled]),
                'countries' => $countries->where('domain_id', $d->id)->values()->map(fn ($c) => ['code' => $c->code, 'state' => $c->state]),
            ]),
        ]);
    }

    public function storePath(Request $request, string $domain)
    {
        $d = $this->domain($domain);
        $data = $request->validate(['path' => ['required', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._~\/*-]*$/']]);
        $limit = $this->limits()['paths'];
        if ($limit < 1) {
            throw ApiException::quota('Your plan does not include protected paths.');
        }
        if (FirewallProtectedPath::query()->whereNotNull('domain_id')->whereIn('domain_id', Domain::query()->pluck('id'))->count() >= $limit) {
            throw ApiException::quota("Your plan allows {$limit} protected paths. Remove one or upgrade.");
        }
        if (FirewallProtectedPath::query()->where('domain_id', $d->id)->where('path', $data['path'])->exists()) {
            throw ApiException::conflict("{$data['path']} is already protected on {$d->name}.");
        }
        $p = FirewallProtectedPath::create(['domain_id' => $d->id, 'path' => $data['path'], 'enabled' => true]);
        $this->traefik->push();
        $this->audit->log('firewall.customer_path_add', 'domains', $d->id, ['path' => $p->path]);
        $request->attributes->set('audit_skip', true);

        return $this->data(['id' => $p->id, 'path' => $p->path, 'enabled' => true], 201);
    }

    public function updatePath(Request $request, string $domain, string $id)
    {
        $d = $this->domain($domain);
        $p = FirewallProtectedPath::query()->where('domain_id', $d->id)->findOrFail($id);
        $p->update($request->validate(['enabled' => 'required|boolean']));
        $this->traefik->push();
        $this->audit->log('firewall.customer_path_toggle', 'domains', $d->id, ['path' => $p->path, 'enabled' => $p->enabled]);
        $request->attributes->set('audit_skip', true);

        return $this->data(['id' => $p->id, 'path' => $p->path, 'enabled' => (bool) $p->enabled]);
    }

    public function destroyPath(Request $request, string $domain, string $id)
    {
        $d = $this->domain($domain);
        $p = FirewallProtectedPath::query()->where('domain_id', $d->id)->findOrFail($id);
        $p->delete();
        $this->traefik->push();
        $this->audit->log('firewall.customer_path_remove', 'domains', $d->id, ['path' => $p->path]);
        $request->attributes->set('audit_skip', true);

        return response()->noContent();
    }

    /** Replaces one domain's country rules. */
    public function countries(Request $request, string $domain)
    {
        $d = $this->domain($domain);
        $data = $request->validate([
            'countries' => 'present|array|max:250',
            'countries.*.code' => 'required|string|size:2|alpha',
            'countries.*.state' => 'required|in:block,challenge',
        ]);
        $limit = $this->limits()['countries'];
        $others = FirewallCountry::query()->whereIn('domain_id', Domain::query()->pluck('id'))->where('domain_id', '!=', $d->id)->count();
        $wanted = collect($data['countries'])->keyBy(fn ($c) => strtoupper($c['code']));
        if ($wanted->isNotEmpty() && $limit < 1) {
            throw ApiException::quota('Your plan does not include country rules.');
        }
        if ($others + $wanted->count() > $limit) {
            throw ApiException::quota("Your plan allows {$limit} country rules across your domains.");
        }
        FirewallCountry::query()->where('domain_id', $d->id)->delete();
        foreach ($wanted as $code => $c) {
            FirewallCountry::create(['domain_id' => $d->id, 'code' => $code, 'state' => $c['state']]);
        }
        $this->traefik->push();
        $this->audit->log('firewall.customer_countries', 'domains', $d->id, ['countries' => $wanted->map(fn ($c) => $c['state'])->all()]);
        $request->attributes->set('audit_skip', true);

        return $this->index();
    }
}
