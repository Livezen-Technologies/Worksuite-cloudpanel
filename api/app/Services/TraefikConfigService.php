<?php

namespace App\Services;

use App\Contracts\HandlesCommandResults;
use App\Models\AgentCommand;
use App\Models\Application;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Job;
use App\Models\Secret;
use App\Models\Server;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * CERT_DRIVER=traefik (DOM-002, UDOM-004): Traefik obtains certificates itself (ACME HTTP-01 or
 * DNS-01, wildcards via DNS-01). The API renders the file-provider dynamic configuration (one
 * router per domain with its certResolver) and pushes it with `proxy.config` to each ingress server;
 * `proxy.certs` reads acme.json on the ingress (names + notAfter only) to update certificate status.
 */
class TraefikConfigService implements HandlesCommandResults
{
    /** DNS-01 provider => Traefik/lego credential variables (written as *_FILE secrets). */
    public const DNS_PROVIDERS = [
        'cloudflare' => ['CF_DNS_API_TOKEN'],
        'digitalocean' => ['DO_AUTH_TOKEN'],
        'hetzner' => ['HETZNER_API_KEY'],
        'route53' => ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'],
        'gandiv5' => ['GANDIV5_PERSONAL_ACCESS_TOKEN'],
        'pdns' => ['PDNS_API_URL', 'PDNS_API_KEY'],
    ];

    /** DNS-013: panel DNS provider type => lego provider used for its zones. */
    public const ZONE_PROVIDERS = ['cloudflare' => 'cloudflare', 'powerdns' => 'pdns'];

    public function __construct(private CommandBus $bus, private JobService $jobs, private FirewallConfigService $firewall, private CrowdSecConfigService $crowdsec) {}

    public static function enabled(): bool
    {
        return config('platform.drivers.certificates') === 'traefik';
    }

    /** Ingress servers that receive the dynamic config. */
    public function ingressServers(): Collection
    {
        $ids = config('platform.traefik.ingress_server_ids');
        if ($ids) {
            return Server::allTenants()->whereIn('id', $ids)->get();
        }
        $tagged = Server::allTenants()->get()->filter(fn (Server $s) => in_array('ingress', $s->tags ?? [], true));
        if ($tagged->isNotEmpty()) {
            return $tagged->values();
        }
        // Without a dedicated ingress every app server runs its own Traefik.
        // Servers pushed before stay included so removed routers are removed there too.
        $serverIds = Application::allTenants()->whereIn('id', Domain::allTenants()->whereNotNull('application_id')->select('application_id'))->pluck('server_id')
            ->merge(Cache::get('traefik-config:servers', []))->filter()->unique();

        return Server::allTenants()->whereIn('id', $serverIds)->get();
    }

    /** AV-9: modes a domain can choose. */
    public const WAF_MODES = ['off', 'detect', 'block'];

    /** The Coraza middleware for a domain, or null when the WAF is off for it or not installed. */
    public static function wafMiddleware(Domain $domain): ?array
    {
        $waf = $domain->options['waf'] ?? [];
        $mode = $waf['mode'] ?? 'off';
        if (! config('platform.waf.enabled') || $mode === 'off') {
            return null;
        }
        $directives = [
            'Include @coraza.conf-recommended',
            'Include @crs-setup.conf.example',
            'Include @owasp_crs/*.conf',
            'SecRuleEngine '.($mode === 'block' ? 'On' : 'DetectionOnly'),
        ];
        foreach ($waf['exclusions'] ?? [] as $id) {
            $directives[] = 'SecRuleRemoveById '.(int) $id;
        }

        return ['plugin' => [config('platform.waf.plugin') => ['directives' => $directives]]];
    }

    public function resolverFor(Domain $domain): string
    {
        if ($domain->challenge === 'dns-01') {
            $zone = $domain->dns_secret_id ? null : DnsZone::forDomain($domain->name, $domain->organization_id);
            $provider = $zone ? self::ZONE_PROVIDERS[$zone->provider->type] : ($domain->dns_provider ?: 'cloudflare');

            return config('platform.traefik.dns_resolver_prefix').$provider;
        }

        return config('platform.traefik.http_resolver');
    }

    public static function routerName(Domain $domain): string
    {
        return 'dmp-'.trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace('*.', 'wildcard.', $domain->name))), '-');
    }

    /** Domains served through the server's Traefik (all tenants: the ingress is shared). */
    private function domainsFor(Server $server, bool $dedicated): Collection
    {
        return Domain::allTenants()->with('application')->where('dns_status', 'verified')->whereNotNull('application_id')->get()
            ->filter(fn (Domain $d) => $d->application && ($dedicated || $d->application->server_id === $server->id))->values();
    }

    /** @return array{http: array}|array{} Dynamic configuration for one ingress server; empty when it routes nothing. */
    public function render(Server $server, bool $dedicated = true): array
    {
        $routers = [];
        $middlewares = [];
        $suffix = config('platform.traefik.docker_service_suffix');
        foreach ($this->domainsFor($server, $dedicated) as $domain) {
            $app = $domain->application;
            $name = self::routerName($domain);
            $service = 'app-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($app->name)).$suffix;
            $base = ltrim($domain->name, '*.');
            if ($domain->wildcard) {
                $rule = 'HostRegexp(`^[a-z0-9-]+\\.'.str_replace('.', '\\.', $base).'$`) || Host(`'.$base.'`)';
                $tlsDomains = [['main' => $base, 'sans' => ['*.'.$base]]];
            } else {
                $hosts = $domain->www_redirect ? [$domain->name, 'www.'.$domain->name] : [$domain->name];
                $rule = implode(' || ', array_map(fn ($h) => "Host(`{$h}`)", $hosts));
                $tlsDomains = [['main' => $domain->name] + ($domain->www_redirect ? ['sans' => ['www.'.$domain->name]] : [])];
            }
            $routers[$name] = [
                'rule' => $rule, 'entryPoints' => [config('platform.traefik.entrypoint')], 'service' => $service,
                'tls' => ['certResolver' => $this->resolverFor($domain), 'domains' => $tlsDomains],
            ];
            if ($domain->www_redirect) {
                $middlewares[$name.'-www'] = ['redirectRegex' => ['regex' => '^https?://www\\.'.preg_quote($domain->name).'/(.*)', 'replacement' => "https://{$domain->name}/\${1}", 'permanent' => true]];
                $routers[$name]['middlewares'] = [$name.'-www'];
            }
            // FW-001: firewall rules for this domain. Path-scoped rules need
            // their own higher-priority router, since a middleware cannot match
            // a path; site-wide ones attach to the domain's router.
            $fw = $this->firewall->renderFor($domain, $name, $service, $rule);
            $middlewares += $fw['middlewares'];
            $fw['routers'] && $routers += $fw['routers'];
            if ($fw['site']) {
                $routers[$name]['middlewares'] = array_merge($routers[$name]['middlewares'] ?? [], $fw['site']);
            }
            // FW-001 via CrowdSec: the bouncer runs first on the domain's router and on
            // every path router, so a banned IP never reaches a rate limit or the app.
            if (CrowdSecConfigService::enabled() && ($org = \App\Models\Organization::find($domain->organization_id))) {
                $bouncer = CrowdSecConfigService::middlewareName($org->id);
                $middlewares[$bouncer] ??= $this->crowdsec->middleware($org);
                foreach (array_merge([$name], array_keys($fw['routers'])) as $r) {
                    $routers[$r]['middlewares'] = array_values(array_unique(array_merge([$bouncer], $routers[$r]['middlewares'] ?? [])));
                }
            }
        }
        // AV-9: the WAF runs after the CrowdSec bouncer, so banned sources never cost a rule evaluation.
        foreach ($this->domainsFor($server, $dedicated) as $domain) {
            $waf = self::wafMiddleware($domain);
            if (! $waf) {
                continue;
            }
            $name = self::routerName($domain);
            $middlewares[$name.'-waf'] = $waf;
            foreach (array_keys($routers) as $r) {
                if ($r === $name || str_starts_with($r, $name.'-')) {
                    $mw = $routers[$r]['middlewares'] ?? [];
                    $at = (CrowdSecConfigService::enabled() && $mw && str_starts_with($mw[0], 'dmp-crowdsec-')) ? 1 : 0;
                    array_splice($mw, $at, 0, [$name.'-waf']);
                    $routers[$r]['middlewares'] = array_values(array_unique($mw));
                }
            }
        }
        // Traefik rejects an empty routers map ("routers cannot be a standalone
        // element") and drops the whole file-provider configuration when it sees
        // one - taking every other site on the ingress down with it. With nothing
        // to route, emit no dynamic section at all.
        if (! $routers && ! $middlewares) {
            return [];
        }

        $http = ['routers' => (object) $routers];
        if ($middlewares) {
            $http['middlewares'] = $middlewares;
        }

        return ['http' => $http];
    }

    public function yaml(Server $server, bool $dedicated = true): string
    {
        $config = $this->render($server, $dedicated);
        $header = "# Managed by Docker Manager Portal (proxy.config). Do not edit.\n";

        return $config === [] ? $header : $header.Yaml::dump(json_decode(json_encode($config), true), 8, 2);
    }

    /** DNS-01 credentials from encrypted secrets, as {VAR: value} for *_FILE files on the ingress. */
    public function dnsSecrets(): array
    {
        $out = [];
        // DNS-013: domains without their own token use the credentials of the zone that holds them.
        // Lego reads one set of variables per provider, so a token given on a domain wins.
        foreach (Domain::allTenants()->where('challenge', 'dns-01')->whereNull('dns_secret_id')->get() as $domain) {
            $zone = DnsZone::forDomain($domain->name, $domain->organization_id);
            $c = $zone?->provider?->credentials ?? [];
            $out += match ($zone?->provider?->type) {
                'cloudflare' => ['CF_DNS_API_TOKEN' => (string) ($c['token'] ?? '')],
                'powerdns' => ['PDNS_API_URL' => (string) ($c['api_url'] ?? ''), 'PDNS_API_KEY' => (string) ($c['api_key'] ?? ''), 'PDNS_SERVER_NAME' => (string) ($c['server_id'] ?? 'localhost')],
                default => [],
            };
        }
        foreach (Domain::allTenants()->where('challenge', 'dns-01')->whereNotNull('dns_secret_id')->get() as $domain) {
            $secret = Secret::allTenants()->find($domain->dns_secret_id);
            $vars = self::DNS_PROVIDERS[$domain->dns_provider ?: 'cloudflare'] ?? [];
            if (! $secret || ! $vars) {
                continue;
            }
            $values = count($vars) > 1 ? (json_decode($secret->value, true) ?: []) : [$vars[0] => $secret->value];
            foreach ($vars as $var) {
                if (isset($values[$var])) {
                    $explicit[$var] = (string) $values[$var];
                }
            }
        }

        return ($explicit ?? []) + $out;
    }

    /** Pushes the rendered config to every ingress server whose content changed. Returns commands queued. */
    public function push(bool $force = false): int
    {
        if (! self::enabled()) {
            return 0;
        }
        $dedicated = (bool) config('platform.traefik.ingress_server_ids') || Server::allTenants()->get()->contains(fn ($s) => in_array('ingress', $s->tags ?? [], true));
        $secrets = $this->dnsSecrets();
        $count = 0;
        foreach ($this->ingressServers() as $server) {
            $files = [config('platform.traefik.file_name') => $this->yaml($server, $dedicated)];
            $cs = CrowdSecConfigService::enabled() ? $this->crowdsec->render($this->domainsFor($server, $dedicated)) : null;
            $hash = hash('sha256', json_encode([$files, $secrets, $cs]));
            $key = 'traefik-config:'.$server->id;
            if (! $force && Cache::get($key) === $hash) {
                continue;
            }
            $this->bus->dispatch($server, 'proxy.config', array_filter(['files' => $files, 'prune_prefix' => 'dmp-', 'secrets' => $secrets ? (object) $secrets : null]));
            if ($cs !== null) {
                $this->bus->dispatch($server, 'crowdsec.apply', ['scenarios' => (object) $cs['scenarios'], 'profiles' => $cs['profiles']]);
            }
            Cache::put($key, $hash, now()->addDays(30));
            Cache::forever('traefik-config:servers', array_values(array_unique(array_merge(Cache::get('traefik-config:servers', []), [$server->id]))));
            $count++;
        }

        return $count;
    }

    /** Queues proxy.certs on every ingress server (scheduled). */
    public function syncCertificates(): int
    {
        $count = 0;
        foreach ($this->ingressServers() as $server) {
            $job = $this->jobs->create('proxy.certs', ['read_acme'], null, [], null, $server->organization_id);
            $this->bus->dispatch($server, 'proxy.certs', [], $job);
            $count++;
        }

        return $count;
    }

    public function onCommandFinished(AgentCommand $command, Job $job): void
    {
        if ($command->status !== 'succeeded') {
            $this->jobs->fail($job, $command->message ?: 'Reading acme.json failed', 'read_acme');

            return;
        }
        $updated = $this->applyCertificates($command->output['certificates'] ?? []);
        $this->jobs->succeed($job, ['certificates' => count($command->output['certificates'] ?? []), 'updated' => $updated]);
    }

    /** Matches acme.json entries to domains and records expiry (no key material ever arrives here). */
    public function applyCertificates(array $certs): int
    {
        $updated = 0;
        foreach ($certs as $cert) {
            if (empty($cert['domain']) || empty($cert['not_after'])) {
                continue;
            }
            $names = array_merge([$cert['domain']], $cert['sans'] ?? []);
            $domains = Domain::allTenants()->whereIn('name', array_merge($names, array_map(fn ($n) => '*.'.ltrim($n, '*.'), $names)))->get();
            foreach ($domains as $domain) {
                app(Tenant::class)->as($domain->organization_id, function () use ($domain, $cert, $names, &$updated) {
                    $expires = Carbon::parse($cert['not_after']);
                    $current = Certificate::where('domain_id', $domain->id)->whereIn('status', ['active', 'issuing'])->latest()->first();
                    if ($current && $current->status === 'active' && $current->expires_at?->equalTo($expires)) {
                        $current->update(['checked_at' => now()]);

                        return;
                    }
                    // The "issuing" placeholder is superseded by the real certificate.
                    Certificate::where('domain_id', $domain->id)->where('status', 'issuing')->delete();
                    Certificate::where('domain_id', $domain->id)->where('status', 'active')->update(['status' => 'replaced']);
                    Certificate::create([
                        'domain_id' => $domain->id, 'issuer' => $cert['issuer'] ?? 'letsencrypt', 'status' => $expires->isPast() ? 'expired' : 'active',
                        'names' => $names, 'resolver' => $cert['resolver'] ?? null,
                        'issued_at' => isset($cert['not_before']) ? Carbon::parse($cert['not_before']) : null, 'expires_at' => $expires, 'checked_at' => now(),
                    ]);
                    $domain->update(['https_status' => $expires->isPast() ? 'failed' : 'active']);
                    $updated++;
                });
            }
        }

        return $updated;
    }
}
