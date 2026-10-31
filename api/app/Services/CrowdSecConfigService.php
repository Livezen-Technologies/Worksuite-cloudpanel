<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\FirewallCountry;
use App\Models\FirewallIpList;
use App\Models\FirewallProtectedPath;
use App\Models\FirewallRule;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Yaml\Yaml;

/**
 * FW-001 enforcement through CrowdSec. Renders the firewall into what CrowdSec
 * and its Traefik bouncer understand:
 *
 *   rule (block, challenge, recaptcha, log)  a leaky-bucket scenario on the rule's
 *                                            hosts, paths and methods, sized from its
 *                                            limit and window
 *   country policy                           a trigger scenario on evt.Enriched.IsoCode
 *                                            (CrowdSec's GeoIP enrichment)
 *   protected path                           a trigger scenario that asks for a captcha
 *   block list (FW-011)                      a trigger scenario on IpInRange, banned
 *   allow list (FW-011)                      a whitelist parser, so nothing on it is ever
 *                                            counted, challenged or banned for those hosts
 *   under attack (FW-010)                    a trigger scenario on every request, captcha
 *
 * profiles.yaml then turns each scenario's alert into a ban or a captcha decision,
 * and the bouncer middleware attached to every router serves the block page or the
 * captcha. Rate limits stay native Traefik middlewares (FirewallConfigService), so a
 * block rule answers 429 at once and the ban follows from CrowdSec.
 *
 * Scenario names carry the record they came from (dmp/fw-<rule id>, dmp/geo-<org id>-<state>,
 * dmp/path-<id>) so alerts can be traced back when they become firewall events.
 */
class CrowdSecConfigService
{
    public static function enabled(): bool
    {
        return (bool) config('platform.crowdsec.enabled') && config('platform.crowdsec.bouncer_key');
    }

    /** The bouncer middleware for one organisation (its own captcha keys). */
    public static function middlewareName(string $organizationId): string
    {
        return 'dmp-crowdsec-'.substr(str_replace('-', '', $organizationId), 0, 12);
    }

    public function middleware(Organization $org): array
    {
        $fw = $this->settings($org);
        $bouncer = [
            'enabled' => true,
            'crowdsecMode' => 'stream',
            'updateIntervalSeconds' => config('platform.crowdsec.update_seconds'),
            'crowdsecLapiScheme' => config('platform.crowdsec.lapi_scheme'),
            'crowdsecLapiHost' => config('platform.crowdsec.lapi_host'),
            'crowdsecLapiKey' => config('platform.crowdsec.bouncer_key'),
            // Branded pages from Public pages (bot check), pushed with proxy.config.
            'banHTMLFilePath' => rtrim((string) config('platform.crowdsec.pages_dir'), '/').'/dmp-ban.html',
        ];
        if (config('platform.crowdsec.trusted_proxies')) {
            $bouncer['forwardedHeadersTrustedIPs'] = array_values(config('platform.crowdsec.trusted_proxies'));
        }
        $secret = $this->captchaSecret($fw);
        if (($fw['site_key'] ?? '') !== '' && $secret) {
            $bouncer += [
                'captchaProvider' => $fw['provider'] === 'recaptcha' ? 'recaptcha' : ($fw['provider'] === 'hcaptcha' ? 'hcaptcha' : 'turnstile'),
                'captchaSiteKey' => $fw['site_key'],
                'captchaSecretKey' => $secret,
                'captchaGracePeriodSeconds' => 60 * max(1, (int) ($fw['clearance_minutes'] ?? 30)),
                'captchaHTMLFilePath' => rtrim((string) config('platform.crowdsec.pages_dir'), '/').'/dmp-captcha.html',
            ];
        }

        return ['plugin' => [config('platform.crowdsec.plugin') => $bouncer]];
    }

    /**
     * Scenario files and profiles.yaml for the domains an ingress routes.
     *
     * @param  Collection<int, Domain>  $domains
     * @return array{scenarios: array<string, string>, profiles: string, whitelist: string}
     */
    public function render(Collection $domains): array
    {
        $scenarios = [];
        $bans = [];
        $captchas = [];
        $allowed = [];
        $hostsByOrg = $domains->groupBy('organization_id')->map(fn ($ds) => $this->hosts($ds));

        foreach ($hostsByOrg as $orgId => $orgHosts) {
            foreach (FirewallRule::allTenants()->where('organization_id', $orgId)->where('enabled', true)->whereIn('action', ['block', 'challenge', 'recaptcha', 'log'])->get() as $rule) {
                $hosts = $rule->domain_id ? $this->hosts($domains->where('id', $rule->domain_id)) : $orgHosts;
                if (! $hosts) {
                    continue;
                }
                $name = 'dmp/fw-'.$rule->id;
                $limit = max(1, $rule->limit);
                $scenarios['dmp-fw-'.$this->short($rule->id).'.yaml'] = $this->yaml([
                    'type' => 'leaky',
                    'name' => $name,
                    'description' => 'Firewall rule: '.$rule->name,
                    'filter' => $this->filter($hosts, (array) $rule->paths, (array) ($rule->methods ?? [])),
                    'groupby' => 'evt.Meta.source_ip',
                    'capacity' => $limit,
                    'leakspeed' => max(1, (int) ceil($rule->window_seconds / $limit)).'s',
                    'blackhole' => max(60, $rule->window_seconds).'s',
                    'labels' => ['service' => 'http', 'type' => 'dmp_firewall', 'remediation' => $rule->action !== 'log'],
                ]);
                match ($rule->action) {
                    'block' => $bans[] = $name,
                    'challenge', 'recaptcha' => $captchas[] = $name,
                    default => null,
                };
            }

            $org = Organization::find($orgId);
            $mode = $org ? ($this->settings($org)['country_mode'] ?? 'block') : 'block';
            foreach (FirewallCountry::allTenants()->where('organization_id', $orgId)->get()->groupBy(fn ($c) => $c->state.'|'.($c->domain_id ?? '*')) as $key => $codes) {
                [$state, $domainId] = explode('|', $key);
                $hosts = $domainId === '*' ? $orgHosts : $this->hosts($domains->where('id', $domainId));
                if (! $hosts) {
                    continue;
                }
                $list = $this->list($codes->pluck('code')->map(fn ($c) => strtoupper($c))->unique()->values()->all());
                $country = $mode === 'allow' && $state === 'block'
                    ? "evt.Enriched.IsoCode != '' && !(evt.Enriched.IsoCode in {$list})"
                    : "evt.Enriched.IsoCode in {$list}";
                $name = 'dmp/geo-'.$orgId.'-'.$state.($domainId === '*' ? '' : '-'.$this->short($domainId));
                $scenarios['dmp-geo-'.$this->short($orgId).'-'.$state.($domainId === '*' ? '' : '-'.$this->short($domainId)).'.yaml'] = $this->yaml([
                    'type' => 'trigger',
                    'name' => $name,
                    'description' => 'Country policy ('.$state.')',
                    'filter' => $this->filter($hosts, ['*'], []).' && '.$country,
                    'groupby' => 'evt.Meta.source_ip',
                    'blackhole' => '1m',
                    'labels' => ['service' => 'http', 'type' => 'dmp_country', 'remediation' => true],
                ]);
                $state === 'block' ? $bans[] = $name : $captchas[] = $name;
            }

            foreach (FirewallProtectedPath::allTenants()->where('organization_id', $orgId)->where('enabled', true)->get() as $path) {
                $hosts = $path->domain_id ? $this->hosts($domains->where('id', $path->domain_id)) : $orgHosts;
                if (! $hosts) {
                    continue;
                }
                $name = 'dmp/path-'.$path->id;
                $scenarios['dmp-path-'.$this->short($path->id).'.yaml'] = $this->yaml([
                    'type' => 'trigger',
                    'name' => $name,
                    'description' => 'Challenge-protected path '.$path->path,
                    'filter' => $this->filter($hosts, [$path->path], []),
                    'groupby' => 'evt.Meta.source_ip',
                    'blackhole' => '1m',
                    'labels' => ['service' => 'http', 'type' => 'dmp_path', 'remediation' => true],
                ]);
                $captchas[] = $name;
            }

            // FW-011. Expired entries are left out; firewall:expire-ips deletes them and re-renders.
            $lists = FirewallIpList::allTenants()->where('organization_id', $orgId)->active()->get();
            foreach ($lists->groupBy(fn ($e) => $e->list.'|'.($e->domain_id ?? '*')) as $key => $entries) {
                [$list, $domainId] = explode('|', $key);
                $hosts = $domainId === '*' ? $orgHosts : $this->hosts($domains->where('id', $domainId));
                if (! $hosts) {
                    continue;
                }
                $ranges = '('.implode(' || ', $entries->map(fn ($e) => "IpInRange(evt.Meta.source_ip, '".$e->cidr."')")->all()).')';
                if ($list === 'allow') {
                    $allowed[] = 'evt.Meta.target_fqdn in '.$this->list($hosts).' && '.$ranges;

                    continue;
                }
                $suffix = $this->short($orgId).($domainId === '*' ? '' : '-'.$this->short($domainId));
                $name = 'dmp/block-'.$orgId.($domainId === '*' ? '' : '-'.$this->short($domainId));
                $scenarios['dmp-block-'.$suffix.'.yaml'] = $this->yaml([
                    'type' => 'trigger',
                    'name' => $name,
                    'description' => 'IP block list',
                    'filter' => $this->filter($hosts, ['*'], []).' && '.$ranges,
                    'groupby' => 'evt.Meta.source_ip',
                    'blackhole' => '1m',
                    'labels' => ['service' => 'http', 'type' => 'dmp_blocklist', 'remediation' => true],
                ]);
                $bans[] = $name;
            }

            // FW-010. Under attack: every visitor on the scope gets a captcha.
            $fw = $org ? $this->settings($org) : [];
            if (! empty($fw['under_attack'])) {
                $scope = $fw['under_attack_domain_id'] ?? null;
                $hosts = $scope ? $this->hosts($domains->where('id', $scope)) : $orgHosts;
                if ($hosts) {
                    $name = 'dmp/attack-'.$orgId;
                    $scenarios['dmp-attack-'.$this->short($orgId).'.yaml'] = $this->yaml([
                        'type' => 'trigger',
                        'name' => $name,
                        'description' => 'Under attack mode',
                        'filter' => $this->filter($hosts, ['*'], []),
                        'groupby' => 'evt.Meta.source_ip',
                        'blackhole' => '1m',
                        'labels' => ['service' => 'http', 'type' => 'dmp_attack', 'remediation' => true],
                    ]);
                    $captchas[] = $name;
                }
            }
        }
        ksort($scenarios);

        return ['scenarios' => $scenarios, 'profiles' => $this->profiles($bans, $captchas), 'whitelist' => $this->whitelist($allowed)];
    }

    /** FW-011. The allow list as a CrowdSec whitelist parser (s02-enrich); empty when there is none. */
    public function whitelist(array $expressions): string
    {
        if (! $expressions) {
            return '';
        }

        return $this->yaml([
            'name' => 'dmp/allowlist',
            'description' => 'Firewall allow list',
            'whitelist' => ['reason' => 'Firewall allow list', 'expression' => array_values($expressions)],
        ]);
    }

    /** Profiles: the panel's own first, then CrowdSec's defaults for every other scenario. */
    public function profiles(array $bans, array $captchas): string
    {
        $docs = [];
        $ban = (int) config('platform.crowdsec.ban_minutes').'m';
        if ($captchas) {
            $docs[] = ['name' => 'dmp_captcha', 'filters' => ['Alert.Remediation == true && Alert.GetScope() == "Ip" && Alert.GetScenario() in '.$this->list($captchas)],
                'decisions' => [['type' => 'captcha', 'duration' => $ban]], 'on_success' => 'break'];
        }
        if ($bans) {
            $docs[] = ['name' => 'dmp_ban', 'filters' => ['Alert.Remediation == true && Alert.GetScope() == "Ip" && Alert.GetScenario() in '.$this->list($bans)],
                'decisions' => [['type' => 'ban', 'duration' => $ban]], 'on_success' => 'break'];
        }
        $docs[] = ['name' => 'default_ip_remediation', 'filters' => ['Alert.Remediation == true && Alert.GetScope() == "Ip"'],
            'decisions' => [['type' => 'ban', 'duration' => '4h']], 'on_success' => 'break'];
        $docs[] = ['name' => 'default_range_remediation', 'filters' => ['Alert.Remediation == true && Alert.GetScope() == "Range"'],
            'decisions' => [['type' => 'ban', 'duration' => '4h']], 'on_success' => 'break'];

        return "# Managed by Docker Manager Portal (crowdsec.apply). Do not edit.\n".implode("---\n", array_map(fn ($d) => Yaml::dump($d, 6, 2), $docs));
    }

    /** CrowdSec expr for HTTP requests to these hosts, paths and methods (fields from the traefik-logs parser). */
    private function filter(array $hosts, array $paths, array $methods): string
    {
        $parts = ["evt.Meta.service == 'http'", "evt.Meta.log_type in ['http_access-log', 'http_error-log']", 'evt.Meta.target_fqdn in '.$this->list($hosts)];
        $paths = array_values(array_filter($paths, fn ($p) => $p !== '' && $p !== null && $p !== '*'));
        if ($paths) {
            $parts[] = '('.implode(' || ', array_map(fn ($p) => "evt.Meta.http_path matches '".$this->regex($p)."'", $paths)).')';
        }
        if ($methods) {
            $parts[] = 'evt.Meta.http_verb in '.$this->list(array_map('strtoupper', $methods));
        }

        return implode(' && ', $parts);
    }

    /** A firewall path ("/wp-login.php", "/admin/*") as an anchored regular expression. */
    private function regex(string $path): string
    {
        $re = '^'.str_replace('\*', '.*', preg_quote($path, '/'));

        return str_replace(["\\", "'"], ["\\\\", "\\'"], $re);
    }

    private function hosts(Collection $domains): array
    {
        return $domains->flatMap(fn (Domain $d) => $d->www_redirect ? [$d->name, 'www.'.$d->name] : [ltrim($d->name, '*.')])->unique()->values()->all();
    }

    private function list(array $values): string
    {
        return '['.implode(', ', array_map(fn ($v) => "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v)."'", $values)).']';
    }

    private function yaml(array $doc): string
    {
        return "# Managed by Docker Manager Portal (crowdsec.apply). Do not edit.\n".Yaml::dump($doc, 4, 2);
    }

    private function short(string $id): string
    {
        return substr(str_replace('-', '', $id), 0, 12);
    }

    private function settings(Organization $org): array
    {
        return array_replace_recursive(config('platform.default_settings.firewall'), $org->settings['firewall'] ?? []);
    }

    private function captchaSecret(array $fw): ?string
    {
        if (empty($fw['secret_key_encrypted'])) {
            return null;
        }
        try {
            return Crypt::decryptString($fw['secret_key_encrypted']);
        } catch (\Throwable) {
            return null;
        }
    }
}
