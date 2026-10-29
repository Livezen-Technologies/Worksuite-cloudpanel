<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\FirewallRule;
use Illuminate\Support\Collection;

/**
 * FW-001 step 3. Renders firewall rules into Traefik dynamic configuration,
 * the way TraefikConfigService renders routers. Called from its render().
 *
 * What Traefik v3 can enforce on its own, and what it cannot:
 *
 *   rate limits   native, via the rateLimit middleware (average/period/burst
 *                 and sourceCriterion). A middleware cannot match a path, so a
 *                 rule scoped to paths gets its own router with a higher
 *                 priority than the domain's router, pointing at the same
 *                 service and carrying the rate limit. Traefik answers 429
 *                 once the limit is exceeded.
 *   ip lists      native, via ipAllowList.
 *   block vs      both answer 429 natively. A distinct hard block, and the
 *   throttle      challenge and reCAPTCHA actions, need a middleware that can
 *                 serve a challenge page - CrowdSec, Cloudflare Turnstile at
 *                 the edge, or a custom plugin. None is deployed.
 *   country       needs a GeoIP plugin (traefik-geoip2 or CrowdSec). Not
 *                 deployed, so country rules render nothing.
 *
 * unsupported() names the rules whose action cannot be honoured, so the UI can
 * say so rather than appearing to enforce something it does not.
 */
class FirewallConfigService
{
    /** Actions Traefik can enforce with no plugin. Everything else degrades to a 429. */
    public const NATIVE_ACTIONS = ['block', 'throttle', 'log'];

    /** How a rule counts requests, as a Traefik sourceCriterion. */
    private function sourceCriterion(FirewallRule $rule): array
    {
        return match ($rule->counted_by) {
            'token_else_ip' => ['requestHeaderName' => 'Authorization'],
            'ip_session' => ['requestHeaderName' => 'Cookie'],
            default => ['ipStrategy' => ['depth' => (int) config('platform.traefik.forwarded_depth', 1)]],
        };
    }

    /** Rules in force for one domain: its own, plus the organisation-wide ones. */
    public function rulesFor(Domain $domain): Collection
    {
        return FirewallRule::allTenants()
            ->where('organization_id', $domain->organization_id)
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('domain_id')->orWhere('domain_id', $domain->id))
            ->orderBy('priority')
            ->get();
    }

    public static function middlewareName(FirewallRule $rule): string
    {
        return 'dmp-fw-'.substr(str_replace('-', '', $rule->id), 0, 12);
    }

    /**
     * Middlewares and extra routers for one domain.
     *
     * @param  string  $router   the domain's own router name
     * @param  string  $service  the service both it and any path router point at
     * @return array{middlewares: array, routers: array, unsupported: array}
     */
    public function renderFor(Domain $domain, string $router, string $service, string $rule, int $priority = 100): array
    {
        $middlewares = [];
        $routers = [];
        $unsupported = [];
        $wholeSite = [];

        foreach ($this->rulesFor($domain) as $fw) {
            if (! in_array($fw->action, self::NATIVE_ACTIONS, true)) {
                $unsupported[] = ['id' => $fw->id, 'name' => $fw->name, 'action' => $fw->action];

                continue;
            }
            if ($fw->action === 'log') {
                // Nothing to enforce; the rule exists to record events.
                continue;
            }
            $name = self::middlewareName($fw);
            $middlewares[$name] = ['rateLimit' => [
                'average' => $fw->limit,
                'period' => $fw->window_seconds.'s',
                'burst' => max(1, (int) ceil($fw->limit / 10)),
                'sourceCriterion' => $this->sourceCriterion($fw),
            ]];

            $paths = array_values(array_filter((array) $fw->paths, fn ($p) => $p !== '' && $p !== null));
            if (! $paths || in_array('*', $paths, true)) {
                $wholeSite[] = $name;

                continue;
            }

            // A middleware cannot match a path, so give the rule its own router.
            $clauses = array_map(function (string $p) {
                return str_contains($p, '*')
                    ? 'PathRegexp(`^'.str_replace('\*', '.*', preg_quote(rtrim($p, '*').'*', '`')).'`)'
                    : 'PathPrefix(`'.$p.'`)';
            }, $paths);
            $match = '('.$rule.') && ('.implode(' || ', $clauses).')';
            if ($fw->methods) {
                $match .= ' && ('.implode(' || ', array_map(fn ($m) => 'Method(`'.strtoupper($m).'`)', $fw->methods)).')';
            }
            $routers[$router.'-fw-'.substr(str_replace('-', '', $fw->id), 0, 8)] = [
                'rule' => $match,
                'entryPoints' => [config('platform.traefik.entrypoint')],
                'service' => $service,
                'priority' => $priority + 1000 - $fw->priority,
                'middlewares' => [$name],
            ];
        }

        return ['middlewares' => $middlewares, 'routers' => $routers, 'site' => $wholeSite, 'unsupported' => $unsupported];
    }

    /** Rules across the organisation whose action Traefik cannot honour as configured. */
    public function unsupported(): array
    {
        return FirewallRule::where('enabled', true)
            ->whereNotIn('action', self::NATIVE_ACTIONS)
            ->get()
            ->map(fn (FirewallRule $r) => ['id' => $r->id, 'name' => $r->name, 'action' => $r->action])
            ->all();
    }
}
