<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Services\TraefikConfigService;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * EDGE-SCAN: the enterprise edges answer scanner probes (/.env, /.git, wp-login.php,
 * phpMyAdmin...) with 403 themselves, so the app node never sees them. /.well-known/ and
 * ordinary paths are never matched, and panel-01's render does not change.
 */
class EdgeScannerBlockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.drivers.certificates' => 'traefik']);
    }

    private function app(string $token, Server $server, string $name, bool $enterprise): void
    {
        $id = $this->api($token)->postJson('/api/v1/applications', ['name' => $name, 'source_type' => 'image', 'image' => 'ghcr.io/acme/x:1', 'server_id' => $server->id, 'port' => 8080])->assertCreated()->json('data.id');
        if ($enterprise) {
            $app = Application::allTenants()->findOrFail($id);
            $app->update(['source' => array_merge((array) $app->source, ['enterprise_deployment_id' => 'dep-'.$name])]);
        }
        $this->api($token)->postJson('/api/v1/domains', ['name' => "{$name}.acme.test", 'application_id' => $id, 'target_port' => 8080])->assertCreated();
        Domain::allTenants()->where('name', "{$name}.acme.test")->update(['dns_status' => 'verified']);
    }

    /** @return array{0: Server, 1: Server, 2: Server} panel-01, frontdoor-01, frontdoor-02 */
    private function fleet(): array
    {
        [, $org, $token] = $this->org();
        $panel = $this->server($org, ['name' => 'panel-01', 'tags' => ['ingress']]);
        $node = $this->server($org, ['name' => 'node-05', 'ip' => '203.0.113.5']);
        $this->app($token, $panel, 'shop', false);
        $this->app($token, $node, 'ent-acme', true);
        $e1 = $this->server($org, ['name' => 'frontdoor-01', 'ip' => '51.254.220.203', 'tags' => ['ingress', 'enterprise-edge']]);
        $e2 = $this->server($org, ['name' => 'frontdoor-02', 'ip' => '51.254.213.183', 'tags' => ['ingress', 'enterprise-edge']]);

        return [$panel, $e1, $e2];
    }

    private function http(Server $s): array
    {
        return Yaml::parse(app(TraefikConfigService::class)->yaml($s))['http'];
    }

    /** The PathRegexp pattern of a rule, as a PCRE (RE2 and PCRE agree on the patterns used). */
    private function pattern(string $rule): string
    {
        $this->assertSame(1, preg_match('/PathRegexp\(`\(\?i\)(.+)`\)$/', $rule, $m), $rule);

        return '~'.$m[1].'~i';
    }

    public function test_edges_answer_scanner_paths_with_403_before_the_app(): void
    {
        [, $e1, $e2] = $this->fleet();
        foreach ([$e1, $e2] as $edge) {
            $http = $this->http($edge);
            $main = $http['routers']['dmp-ent-acme-acme-test'];
            $block = $http['routers']['dmp-ent-acme-acme-test-scanners'];
            $this->assertStringStartsWith('(Host(`ent-acme.acme.test`)) && PathRegexp(`(?i)', $block['rule']);
            $this->assertSame(TraefikConfigService::SCANNER_PRIORITY, $block['priority']);
            $this->assertGreaterThan(900000, $block['priority'], 'above IP blocks and page takeovers');
            $this->assertSame([TraefikConfigService::SCANNER_DENY], $block['middlewares'], 'no CrowdSec, WAF or app middleware runs first');
            $this->assertSame($main['tls'], $block['tls']);
            $this->assertSame($main['entryPoints'], $block['entryPoints']);
            $this->assertSame($main['service'], $block['service']);
            $this->assertSame(['ipAllowList' => ['sourceRange' => ['127.0.0.1/32']]], $http['middlewares'][TraefikConfigService::SCANNER_DENY], 'nobody passes: Traefik answers 403');

            $re = $this->pattern($block['rule']);
            foreach (['/.env', '/.env.bak', '/api/.env', '/.git/config', '/.git/HEAD', '/.DS_Store', '/.ds_store', '/.htaccess', '/.svn/entries',
                '/wp-login.php', '/blog/wp-login.php', '/xmlrpc.php', '/wp-admin/', '/wp-includes/x.js', '/wp-config.php.bak',
                '/phpmyadmin', '/phpMyAdmin/', '/pma/index.php', '/adminer.php', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
                '/phpinfo.php', '/cgi-bin/luci', '/.aws/credentials', '/.ssh/id_rsa'] as $p) {
                $this->assertSame(1, preg_match($re, $p), "{$p} is blocked");
            }
            foreach (['/', '/login', '/index.php', '/api/v1/tenders', '/assets/app.js', '/environment', '/git', '/admin', '/wp', '/pmaster',
                '/.well-known/acme-challenge/AbC_123-xyz', '/.well-known/openid-configuration', '/.well-known/security.txt',
                '/.well-known/assetlinks.json', '/.well-known/apple-app-site-association', '/uploads/report.env.pdf'] as $p) {
                $this->assertSame(0, preg_match($re, $p), "{$p} still reaches the app");
            }
        }
    }

    public function test_panel_01_render_is_byte_identical_and_carries_no_scanner_routers(): void
    {
        [$panel, $e1] = $this->fleet();
        $with = app(TraefikConfigService::class)->yaml($panel);
        config(['platform.traefik.edge_block_scanners' => false]);
        $this->assertSame($with, app(TraefikConfigService::class)->yaml($panel), 'the block never touches panel-01');
        $this->assertStringNotContainsString('-scanners', $with);
        $this->assertStringNotContainsString(TraefikConfigService::SCANNER_DENY, $with);
        $this->assertArrayNotHasKey('dmp-ent-acme-acme-test-scanners', $this->http($e1)['routers'], 'turned off: edges render without it');
    }

    public function test_patterns_that_would_catch_well_known_or_app_paths_are_dropped(): void
    {
        config(['platform.traefik.edge_blocked_paths' => ['/\.well-known/', '/\.', '^/', '/(unclosed', 'a`b', '/\.env']]);
        $this->assertSame('PathRegexp(`(?i)(?:/\.env)`)', TraefikConfigService::scannerPathRule());
        config(['platform.traefik.edge_blocked_paths' => ['/\.well-known/']]);
        $this->assertNull(TraefikConfigService::scannerPathRule(), 'nothing usable: no router at all');
        [, $e1] = $this->fleet();
        $this->assertArrayNotHasKey('dmp-ent-acme-acme-test-scanners', $this->http($e1)['routers']);
    }
}
