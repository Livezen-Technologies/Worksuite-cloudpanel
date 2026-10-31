<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Application;
use App\Models\Container;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Public pages (SRS PUB-1..4). A small unprivileged nginx container, ws-pages, on every ingress
 * server serves static branded pages; Traefik sends traffic to it for maintenance (503),
 * suspended apps (403), expired plans (402), domains without an app (welcome), unknown hosts
 * (404) and, through the errors middleware, apps that are down (502–504, auto-retry). The pages
 * are written as files, so they are served even when the control plane or the app is down.
 */
class PublicPagesService
{
    public const CONTAINER = 'ws-pages';

    public const IMAGE = 'nginxinc/nginx-unprivileged:1.27-alpine';

    public const HTML_VOLUME = 'ws-pages-html';

    public const CONF_VOLUME = 'ws-pages-conf';

    public const PAGES = [
        'maintenance' => ['status' => 503, 'title' => 'We will be right back', 'message' => 'This site is down for scheduled maintenance. Please try again shortly.'],
        'busy' => ['status' => 503, 'title' => 'This site is busy', 'message' => 'The site is not answering right now. This page tries again by itself.'],
        'not_found' => ['status' => 404, 'title' => 'Site not found', 'message' => 'There is no site at this address.'],
        'suspended' => ['status' => 403, 'title' => 'This site is suspended', 'message' => 'This site has been suspended. If it is yours, contact support.'],
        'expired' => ['status' => 402, 'title' => 'This site is paused', 'message' => 'The plan for this site has ended. If it is yours, renew it in the panel.'],
        'sleeping' => ['status' => 503, 'title' => 'Waking this site up', 'message' => 'This preview was asleep to save resources. It is starting now and this page reloads by itself.'],
        'welcome' => ['status' => 200, 'title' => 'Your domain is connected', 'message' => 'This domain points at the platform. Connect it to an app in the panel to put your site here.'],
        // Served by the CrowdSec bouncer in Traefik (FW-001), not by the edge container.
        'captcha' => ['status' => 200, 'title' => 'Checking that you are human', 'message' => 'This site is protected against automated traffic. Complete the check below to continue.'],
        'ban' => ['status' => 403, 'title' => 'Access blocked', 'message' => 'Your address was blocked by this site\'s firewall after unusual activity. Try again later.'],
    ];

    /** Pages the bouncer serves: one for the whole platform, never per site. */
    public const BOT_PAGES = ['captcha', 'ban'];

    public const DEFAULTS = ['maintenance' => false, 'retry_after' => 3600, 'grace_days' => 7, 'pages' => []];

    public function __construct(private CommandBus $bus, private AuditLogger $audit, private EmailTemplateService $emails) {}

    /** Platform-level settings live on the platform organisation (first one), like the email brand. */
    public function settings(): array
    {
        $s = array_replace(self::DEFAULTS, data_get($this->platform()?->settings, 'public_pages', []));
        $s['bypass_token'] ??= null;

        return $s;
    }

    public function saveSettings(array $input, ?string $userId): array
    {
        $org = $this->platform() ?? throw ApiException::conflict('No organisation exists yet.');
        $s = $this->settings();
        foreach (['maintenance', 'retry_after', 'grace_days'] as $k) {
            if (array_key_exists($k, $input)) {
                $s[$k] = $input[$k];
            }
        }
        foreach ($input['pages'] ?? [] as $key => $page) {
            if (isset(self::PAGES[$key])) {
                $s['pages'][$key] = array_filter(array_intersect_key((array) $page, array_flip(['title', 'message', 'link_label', 'link_url'])), fn ($v) => $v !== null && $v !== '');
            }
        }
        $s['bypass_token'] ??= Str::random(32);
        $settings = $org->settings ?? [];
        $settings['public_pages'] = $s;
        $org->update(['settings' => $settings]);
        $this->audit->log('public_pages.update', 'organizations', $org->id, array_intersect_key($input, array_flip(['maintenance', 'retry_after', 'grace_days'])) + ['pages' => array_keys($input['pages'] ?? [])], null, $userId);
        $this->publish();

        return $s;
    }

    /** PUB-2/3: site-level maintenance and page text, kept on the domain. */
    public function saveSite(Domain $domain, array $input, ?string $userId): Domain
    {
        $opts = $domain->options ?? [];
        $pages = $opts['pages'] ?? [];
        if (array_key_exists('maintenance', $input)) {
            $pages['maintenance'] = (bool) $input['maintenance'];
        }
        foreach ($input['pages'] ?? [] as $key => $page) {
            if (isset(self::PAGES[$key]) && ! in_array($key, self::BOT_PAGES, true)) {
                $pages['text'][$key] = array_filter(array_intersect_key((array) $page, array_flip(['title', 'message', 'link_label', 'link_url'])), fn ($v) => $v !== null && $v !== '');
            }
        }
        $opts['pages'] = $pages;
        $domain->update(['options' => $opts]);
        $this->ensureToken();
        $this->audit->log('public_pages.site', 'domains', $domain->id, ['domain' => $domain->name, 'maintenance' => $pages['maintenance'] ?? false], null, $userId);
        $this->publish();

        return $domain;
    }

    /** Which page, if any, takes over a domain instead of its app. */
    public function takeover(Domain $domain): ?string
    {
        if (! $domain->application_id && ! TraefikConfigService::upstream($domain)) {
            return 'welcome';
        }
        if ($this->settings()['maintenance'] || ($domain->options['pages']['maintenance'] ?? false)) {
            return 'maintenance';
        }
        $app = Application::allTenants()->withTrashed()->find($domain->application_id);
        if ($app?->status === 'suspended') {
            return 'suspended';
        }
        if ($app?->asleep_at) {
            return 'sleeping';
        }
        $sub = Subscription::withoutGlobalScopes()->where('organization_id', $domain->organization_id)->latest()->first();
        if ($sub && in_array($sub->status, ['expired', 'canceled', 'cancelled'], true)
            && $sub->current_period_end && $sub->current_period_end->copy()->addDays((int) $this->settings()['grace_days'])->isPast()) {
            return 'expired';
        }

        return null;
    }

    /** Routers and middlewares for the Traefik file provider (added to TraefikConfigService::render). */
    public function traefik(iterable $domains, array $routers): array
    {
        if (! $this->installed()) {
            return [[], []];
        }
        $service = self::CONTAINER.config('platform.traefik.docker_service_suffix');
        $entry = [config('platform.traefik.entrypoint')];
        $token = $this->settings()['bypass_token'];
        $mw = [
            'dmp-pages-errors' => ['errors' => ['status' => ['502-504'], 'service' => $service, 'query' => '/__error/{status}']],
            'dmp-pages-bypass' => ['replacePath' => ['path' => '/__bypass']],
        ];
        $out = [
            // PUB-1: unknown hosts get the 404 page instead of Traefik's bare one.
            'dmp-pages-catchall' => ['rule' => 'HostRegexp(`.+`)', 'priority' => 1, 'entryPoints' => $entry, 'service' => $service, 'middlewares' => ['dmp-pages-p-not_found']],
        ];
        $mw['dmp-pages-p-not_found'] = ['replacePath' => ['path' => '/__page/not_found']];
        foreach ($domains as $domain) {
            $name = TraefikConfigService::routerName($domain);
            $page = $this->takeover($domain);
            $rule = $routers[$name]['rule'] ?? 'Host(`'.$domain->name.'`)';
            $tls = $routers[$name]['tls'] ?? ['certResolver' => config('platform.traefik.http_resolver'), 'domains' => [['main' => $domain->name]]];
            if (isset($routers[$name])) {
                $out[$name] = $routers[$name];
                $out[$name]['middlewares'] = array_values(array_unique(array_merge($routers[$name]['middlewares'] ?? [], ['dmp-pages-errors'])));
            }
            if (! $page) {
                continue;
            }
            $mw['dmp-pages-p-'.$page] = ['replacePath' => ['path' => '/__page/'.$page]];
            $takeover = "({$rule})";
            if ($page === 'maintenance' && $token) {
                // PUB-3: admins open the site once with ?ws_bypass=<token>; the cookie then skips maintenance.
                $out[$name.'-bypass'] = ['rule' => "({$rule}) && Query(`ws_bypass`, `{$token}`)", 'priority' => 100001, 'entryPoints' => $entry, 'service' => $service, 'middlewares' => ['dmp-pages-bypass'], 'tls' => $tls];
                $takeover .= " && !HeaderRegexp(`Cookie`, `ws_bypass={$token}`)";
            }
            if ($page === 'sleeping') {
                // ENV-7: the page's /__wake request goes to the panel, which starts the app.
                $mw['dmp-pages-wake'] = ['replacePath' => ['path' => '/api/v1/hooks/wake']];
                $out[$name.'-wake'] = ['rule' => "({$rule}) && Path(`/__wake`)", 'priority' => 100002, 'entryPoints' => $entry, 'service' => 'dmp-panel-wake', 'middlewares' => ['dmp-pages-wake'], 'tls' => $tls];
            }
            $out[$name.'-page'] = ['rule' => $takeover, 'priority' => 100000, 'entryPoints' => $entry, 'service' => $service, 'middlewares' => ['dmp-pages-p-'.$page], 'tls' => $tls];
        }

        return [$out, $mw];
    }

    /** Domains served by the pages container: verified ones, with or without an app. */
    public function domains()
    {
        return Domain::allTenants()->where('dns_status', 'verified')->where('platform_subdomain', false)->whereNull('application_id')->get()
            ->reject(fn (Domain $d) => TraefikConfigService::upstream($d) !== null)->values();
    }

    public function installed(): bool
    {
        return Container::allTenants()->where('name', self::CONTAINER)->exists();
    }

    /** PUB-4: start the pages container on every ingress server and write the pages. */
    public function install(?string $userId): int
    {
        $n = 0;
        foreach (app(TraefikConfigService::class)->ingressServers() as $server) {
            if (! $server->isOnline() || Container::allTenants()->where('server_id', $server->id)->where('name', self::CONTAINER)->exists()) {
                continue;
            }
            foreach ($this->fileCommands() as [$type, $payload]) {
                $this->bus->dispatch($server, $type, $payload);
            }
            $spec = ContainerSpec::build([
                'name' => self::CONTAINER, 'image' => self::IMAGE, 'read_only_rootfs' => true, 'tmpfs' => ['/tmp'],
                'mounts' => [['source' => self::HTML_VOLUME, 'target' => '/usr/share/nginx/html', 'read_only' => true], ['source' => self::CONF_VOLUME, 'target' => '/etc/nginx/conf.d', 'read_only' => true]],
                'memory_mb' => 64, 'labels' => ['traefik.enable' => 'true', 'traefik.http.services.'.self::CONTAINER.'.loadbalancer.server.port' => '8080'],
            ], null);
            $this->bus->dispatch($server, 'container.create', $spec);
            Container::allTenants()->create(['server_id' => $server->id, 'name' => self::CONTAINER, 'image' => self::IMAGE, 'state' => 'creating', 'spec' => ContainerSpec::redact($spec)]);
            $n++;
        }
        $this->audit->log('public_pages.install', null, null, ['servers' => $n], null, $userId);
        app(TraefikConfigService::class)->push(true);

        return $n;
    }

    /** Rewrites the pages on every server that runs the container, then the routing. */
    public function publish(): void
    {
        foreach (Container::allTenants()->where('name', self::CONTAINER)->get() as $c) {
            $server = Server::allTenants()->find($c->server_id);
            if ($server?->isOnline()) {
                foreach ($this->fileCommands() as [$type, $payload]) {
                    $this->bus->dispatch($server, $type, $payload);
                }
                $this->bus->dispatch($server, 'container.restart', ['container_id' => self::CONTAINER]);
            }
        }
        app(TraefikConfigService::class)->push(true);
    }

    /** @return list<array{0: string, 1: array}> */
    public function fileCommands(): array
    {
        $cmds = [
            ['fs.mkdir', ['volume' => self::HTML_VOLUME, 'path' => 'default', 'exist_ok' => true]],
            ['fs.mkdir', ['volume' => self::HTML_VOLUME, 'path' => 'sites', 'exist_ok' => true]],
            ['fs.write', ['volume' => self::CONF_VOLUME, 'path' => 'default.conf', 'content' => $this->nginxConf()]],
        ];
        foreach (array_diff(array_keys(self::PAGES), self::BOT_PAGES) as $key) {
            $cmds[] = ['fs.write', ['volume' => self::HTML_VOLUME, 'path' => "default/{$key}.html", 'content' => $this->render($key)]];
        }
        foreach (Domain::allTenants()->get()->filter(fn ($d) => ! empty($d->options['pages']['text'])) as $d) {
            $cmds[] = ['fs.mkdir', ['volume' => self::HTML_VOLUME, 'path' => 'sites/'.$d->name, 'exist_ok' => true]];
            foreach (array_keys($d->options['pages']['text']) as $key) {
                $cmds[] = ['fs.write', ['volume' => self::HTML_VOLUME, 'path' => "sites/{$d->name}/{$key}.html", 'content' => $this->render($key, $d)]];
            }
        }

        return $cmds;
    }

    /** PUB-4: a static page under 50 KB with inline styles. */
    public function render(string $key, ?Domain $domain = null): string
    {
        $s = $this->settings();
        $text = array_replace(self::PAGES[$key], $s['pages'][$key] ?? [], $domain?->options['pages']['text'][$key] ?? []);
        // Bot pages are Go templates in the bouncer: text must never open a template action.
        $e = fn ($v) => str_replace(['{{', '}}'], ['&#123;&#123;', '&#125;&#125;'], htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $brand = $this->emails->brand();
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $brand['color']) ? $brand['color'] : '#4F46E5';
        $name = $brand['sender_name'] ?: config('app.name', 'WorkSuite CloudPanel');
        $retry = (int) $s['retry_after'];
        $refresh = $key === 'busy' ? '<meta http-equiv="refresh" content="30">' : ($key === 'sleeping' ? '<meta http-equiv="refresh" content="10">' : ($key === 'maintenance' ? '<meta http-equiv="refresh" content="'.min(300, max(30, $retry)).'">' : ''));
        $link = ! empty($text['link_url']) && preg_match('#^https?://#i', $text['link_url'])
            ? '<p><a href="'.$e($text['link_url']).'">'.$e($text['link_label'] ?? $text['link_url']).'</a></p>' : '';
        $retryNote = $key === 'busy' ? '<p class="m">Trying again in 30 seconds…</p>' : '';
        $head = '';
        if ($key === 'captcha') {
            // The bouncer fills these Go template fields with the provider's script, widget class and site key,
            // and checks the token the widget posts back; the form submits itself once the check passes.
            $head = '<script src="{{ .FrontendJS }}" async defer></script>';
            $retryNote = '<form method="POST" action=""><div class="{{ .FrontendKey }}" data-sitekey="{{ .SiteKey }}" data-callback="captchaCallback"></div></form>'
                .'<script>function captchaCallback(){setTimeout(function(){document.querySelector("form").submit()},300)}</script>'
                .'<noscript><p class="m">Turn on JavaScript to complete the check.</p></noscript>';
        }
        if ($key === 'sleeping') {
            // No scripts on these pages: an image request is what wakes the app.
            $retryNote = '<p class="m">This usually takes under a minute.</p><img src="/__wake" alt="" width="1" height="1">';
        }

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">{$refresh}<title>{$e($text['title'])}</title>{$head}
<style>
:root{color-scheme:light dark;--fg:#111827;--bg:#f4f5f7;--card:#fff;--m:#6b7280}
@media (prefers-color-scheme:dark){:root{--fg:#e5e7eb;--bg:#0b0d12;--card:#151922;--m:#9ca3af}}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--fg);font:16px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:16px}
main{max-width:520px;width:100%;background:var(--card);border-radius:14px;padding:36px;border-top:4px solid {$color}}
h1{margin:0 0 12px;font-size:24px;line-height:1.3}p{margin:0 0 12px}.m{color:var(--m);font-size:14px}a{color:{$color}}
</style></head>
<body><main><h1>{$e($text['title'])}</h1><p>{$e($text['message'])}</p>{$link}{$retryNote}<p class="m">{$e($name)}</p></main></body></html>
HTML;
    }

    /** The CrowdSec bouncer's captcha and ban pages, written next to Traefik's dynamic config. */
    public function bouncerPages(): array
    {
        return ['dmp-captcha.html' => $this->render('captcha'), 'dmp-ban.html' => $this->render('ban')];
    }

    /** PUB-1/4: status, Retry-After and per-site pages, all from files. */
    public function nginxConf(): string
    {
        $retry = (int) $this->settings()['retry_after'];
        $codes = ['maintenance' => 503, 'sleeping' => 503, 'suspended' => 403, 'expired' => 402, 'not_found' => 404];
        $blocks = '';
        foreach ($codes as $page => $code) {
            $retryHeader = $code === 503 ? "    add_header Retry-After {$retry} always;\n" : '';
            $blocks .= "  location = /__page/{$page} {\n{$retryHeader}    error_page {$code} = @{$page};\n    return {$code};\n  }\n  location @{$page} {\n{$retryHeader}    try_files /sites/\$host/{$page}.html /default/{$page}.html =404;\n  }\n";
        }

        return <<<CONF
# Managed by WorkSuite CloudPanel (public pages). Do not edit.
server {
  listen 8080;
  server_tokens off;
  root /usr/share/nginx/html;
  default_type text/html;
  add_header Cache-Control "no-store" always;
  add_header X-Robots-Tag "noindex" always;
{$blocks}  location = /__page/welcome {
    try_files /sites/\$host/welcome.html /default/welcome.html =404;
  }
  # Traefik's errors middleware keeps the app's status code; only the body comes from here.
  location ~ ^/__error/ {
    try_files /sites/\$host/busy.html /default/busy.html =404;
  }
  # PUB-3: Traefik only routes here when the token matches; the cookie then skips maintenance.
  location = /__bypass {
    add_header Set-Cookie "ws_bypass=\$arg_ws_bypass; Path=/; Max-Age=43200; Secure; HttpOnly; SameSite=Lax" always;
    return 302 /;
  }
  location / {
    return 404;
  }
}
CONF;
    }

    /** The admin bypass token exists before any maintenance router needs it. */
    private function ensureToken(): void
    {
        $org = $this->platform();
        if ($org && empty($org->settings['public_pages']['bypass_token'])) {
            $settings = $org->settings ?? [];
            $settings['public_pages'] = array_replace($this->settings(), ['bypass_token' => Str::random(32)]);
            $org->update(['settings' => $settings]);
        }
    }

    private function platform(): ?Organization
    {
        return Organization::query()->orderBy('created_at')->first();
    }
}
