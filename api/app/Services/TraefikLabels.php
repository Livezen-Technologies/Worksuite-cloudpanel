<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Domain;

/** DOM-001 / DOM-006: Traefik routing labels generated on deploy. */
class TraefikLabels
{
    public static function for(Application $app, ?int $port = null): array
    {
        $domains = Domain::where('application_id', $app->id)->get();
        if ($domains->isEmpty() || ! ($port ??= $app->port)) {
            return [];
        }
        $router = 'app-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($app->name));
        if (config('platform.drivers.certificates') === 'traefik') {
            // Routers + TLS come from the file provider (TraefikConfigService); labels only expose the service.
            return ['traefik.enable' => 'true', "traefik.http.services.{$router}.loadbalancer.server.port" => (string) $port] + self::balancing($app, $router);
        }
        $hosts = $domains->flatMap(fn (Domain $d) => $d->www_redirect ? [$d->name, 'www.'.$d->name] : [$d->name])
            ->map(fn ($h) => "Host(`{$h}`)")->implode(' || ');

        $labels = [
            'traefik.enable' => 'true',
            "traefik.http.routers.{$router}.rule" => $hosts,
            "traefik.http.routers.{$router}.entrypoints" => 'websecure',
            "traefik.http.routers.{$router}.tls" => 'true',
            "traefik.http.routers.{$router}.tls.certresolver" => 'letsencrypt',
            "traefik.http.services.{$router}.loadbalancer.server.port" => (string) $port,
            "traefik.http.routers.{$router}-http.rule" => $hosts,
            "traefik.http.routers.{$router}-http.entrypoints" => 'web',
            "traefik.http.routers.{$router}-http.middlewares" => "{$router}-https",
            "traefik.http.middlewares.{$router}-https.redirectscheme.scheme" => 'https',
        ];
        $labels += self::balancing($app, $router);
        $middlewares = [];
        foreach ($domains as $domain) {
            $options = $domain->options ?? [];
            if ($domain->www_redirect) {
                $labels["traefik.http.middlewares.{$router}-www.redirectregex.regex"] = '^https?://www\\.'.preg_quote($domain->name).'/(.*)';
                $labels["traefik.http.middlewares.{$router}-www.redirectregex.replacement"] = "https://{$domain->name}/\${1}";
                $middlewares[] = "{$router}-www";
            }
            foreach ($options['headers'] ?? [] as $name => $value) {
                $labels["traefik.http.middlewares.{$router}-headers.headers.customresponseheaders.{$name}"] = (string) $value;
                $middlewares[] = "{$router}-headers";
            }
            foreach ($options['middlewares'] ?? [] as $m) {
                $middlewares[] = (string) $m;
            }
        }
        if ($middlewares) {
            $labels["traefik.http.routers.{$router}.middlewares"] = implode(',', array_unique($middlewares));
        }

        return $labels;
    }

    /**
     * SCL-3/4: health checks take a failing copy out of rotation; sticky sessions pin a
     * visitor to one copy by cookie. Only when the app runs more than one copy or asks for them.
     */
    public static function balancing(Application $app, string $router): array
    {
        $s = ScalingService::settings($app);
        if (($app->replicas ?? 1) < 2 && empty($app->scaling)) {
            return [];
        }
        $h = $s['health'];
        $out = [
            "traefik.http.services.{$router}.loadbalancer.healthcheck.path" => (string) $h['path'],
            "traefik.http.services.{$router}.loadbalancer.healthcheck.interval" => (int) $h['interval'].'s',
            "traefik.http.services.{$router}.loadbalancer.healthcheck.timeout" => (int) $h['timeout'].'s',
        ];
        if (($s['strategy'] ?? 'round_robin') === 'least_connections') {
            $out["traefik.http.services.{$router}.loadbalancer.strategy"] = 'p2c';
        }
        if ($s['sticky']) {
            $out["traefik.http.services.{$router}.loadbalancer.sticky.cookie.name"] = 'ws_affinity';
            $out["traefik.http.services.{$router}.loadbalancer.sticky.cookie.secure"] = 'true';
            $out["traefik.http.services.{$router}.loadbalancer.sticky.cookie.httponly"] = 'true';
        }

        return $out;
    }
}
