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
}
