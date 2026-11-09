<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/** scripts/setup-relay.sh, run against a scratch root (no systemd, nginx or build). */
class SetupRelayScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/relay-setup-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/api', 0700, true);
        file_put_contents($this->root.'/api/.env', "APP_NAME=Panel\nAPP_URL=https://panel.example.com\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    private function runScript(array $args = []): Process
    {
        $p = new Process(array_merge(['bash', base_path('../scripts/setup-relay.sh'), '--api-env', $this->root.'/api/.env', '--no-build', '--no-systemd', '--no-nginx'], $args), null, ['SETUP_ROOT' => $this->root]);
        $p->run();
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());

        return $p;
    }

    private function env(string $file): array
    {
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$k, $v] = explode('=', $line, 2);
            $out[$k] = $v;
        }

        return $out;
    }

    public function test_it_shares_one_secret_keeps_it_on_rerun_and_never_prints_it(): void
    {
        $first = $this->runScript();
        $api = $this->env($this->root.'/api/.env');
        $relayFile = $this->root.'/etc/dockermgr/relay.env';
        $relay = $this->env($relayFile);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $api['RELAY_SECRET']);
        $this->assertSame($api['RELAY_SECRET'], $relay['RELAY_SECRET']);
        $this->assertSame(['127.0.0.1:8090', 'http://127.0.0.1:8000'], [$relay['RELAY_LISTEN'], $relay['RELAY_API_URL']]);
        $this->assertSame('http://127.0.0.1:8090/relay/healthz', $api['RELAY_HEALTH_URL']);
        $this->assertSame(['Panel', 'https://panel.example.com'], [$api['APP_NAME'], $api['APP_URL']], 'other settings untouched');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($relayFile)), -4));
        $this->assertStringNotContainsString($api['RELAY_SECRET'], $first->getOutput().$first->getErrorOutput());
        $this->assertFileExists($this->root.'/etc/systemd/system/dockermgr-relay.service');
        $this->assertStringContainsString('location /relay/terminal/', file_get_contents($this->root.'/etc/nginx/snippets/dockermgr-relay.conf'));
        $this->assertStringContainsString('upstream dmp_relay { server 127.0.0.1:8090; }', file_get_contents($this->root.'/etc/nginx/conf.d/dockermgr-relay-upstream.conf'));
        $this->assertStringContainsString('include snippets/dockermgr-relay.conf;', $first->getOutput());

        // Again, with SSH on: same secret, one line per key, SSH settings added.
        $this->runScript(['--ssh-port', '2222']);
        $api2 = $this->env($this->root.'/api/.env');
        $relay2 = $this->env($relayFile);
        $this->assertSame($api['RELAY_SECRET'], $api2['RELAY_SECRET']);
        $this->assertSame($api['RELAY_SECRET'], $relay2['RELAY_SECRET']);
        $this->assertSame(1, substr_count(file_get_contents($this->root.'/api/.env'), 'RELAY_SECRET='));
        $this->assertSame([':2222', 'true', '2222'], [$relay2['RELAY_SSH_LISTEN'], $api2['SSH_GATEWAY_ENABLED'], $api2['SSH_GATEWAY_PORT']]);
    }

    public function test_check_mode_reports_without_changing_anything(): void
    {
        $p = new Process(['bash', base_path('../scripts/setup-relay.sh'), '--check', '--api-env', $this->root.'/api/.env'], null, ['SETUP_ROOT' => $this->root]);
        $p->run();
        $this->assertTrue($p->isSuccessful());
        $this->assertStringContainsString('RELAY_SECRET missing', $p->getOutput());
        $this->assertFileDoesNotExist($this->root.'/etc/dockermgr/relay.env');
        $this->assertStringNotContainsString('RELAY_SECRET', file_get_contents($this->root.'/api/.env'));
    }
}
