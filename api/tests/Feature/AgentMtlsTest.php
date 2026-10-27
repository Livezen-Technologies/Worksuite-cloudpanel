<?php

namespace Tests\Feature;

use App\Models\AgentCertificate;
use App\Services\AgentCertificateService;
use App\Support\Tenant;
use Tests\TestCase;

class AgentMtlsTest extends TestCase
{
    private function csr(string $cn = 'node.test'): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $pem);

        return [$pem, $key];
    }

    /** Enrols with a CSR; returns [secret, certificate PEM]. */
    private function enrollWithCsr(): array
    {
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $enrollment = $this->api($token)->postJson('/api/v1/servers/enrollment', ['server_id' => $server->id])->assertCreated()->json('data');
        [$csr] = $this->csr();
        $r = $this->withHeaders(['Authorization' => ''])->postJson('/agent/v1/enroll', ['token' => $enrollment['token'], 'hostname' => 'node.test', 'csr' => $csr])->assertOk()->json();

        return [$r['agent_secret'], $r['certificate']];
    }

    private function hb(string $secret, array $headers = [])
    {
        app(Tenant::class)->set(null);
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(array_merge(['Authorization' => 'Bearer '.$secret, 'Accept' => 'application/json'], $headers))
            ->postJson('/agent/v1/heartbeat', ['agent_version' => '1.0.0', 'containers' => null, 'images' => null, 'networks' => null, 'volumes' => null]);
    }

    public function test_enrolment_csr_is_signed_as_client_certificate_by_local_ca(): void
    {
        [, $pem] = $this->enrollWithCsr();
        $ca = app(AgentCertificateService::class)->ca()['cert'];
        $this->assertSame(1, openssl_x509_verify($pem, openssl_pkey_get_public($ca)));
        $info = openssl_x509_parse($pem);
        $this->assertStringContainsString('TLS Web Client Authentication', $info['extensions']['extendedKeyUsage']);
        $this->assertStringContainsString('CA:FALSE', $info['extensions']['basicConstraints']);
        $this->assertEqualsWithDelta(90 * 86400, $info['validTo_time_t'] - $info['validFrom_time_t'], 86400);
        $this->assertStringContainsString('CA:TRUE', openssl_x509_parse($ca)['extensions']['basicConstraints']);
        $this->get('/agent/v1/ca.crt')->assertOk()->assertSee('BEGIN CERTIFICATE');

        // A bad CSR does not block enrolment (no certificate returned).
        [, $org, $token] = $this->org();
        $server = $this->server($org);
        $enrollment = $this->api($token)->postJson('/api/v1/servers/enrollment', ['server_id' => $server->id])->json('data');
        $r = $this->withHeaders(['Authorization' => ''])->postJson('/agent/v1/enroll', ['token' => $enrollment['token'], 'csr' => 'garbage'])->assertOk()->json();
        $this->assertArrayNotHasKey('certificate', $r);
    }

    public function test_header_mode_verifies_forwarded_certificate_when_present(): void
    {
        config(['security.agent_mtls.mode' => 'header']);
        [$secret, $pem] = $this->enrollWithCsr();
        $sha256 = openssl_x509_fingerprint($pem, 'sha256');
        $sha1 = openssl_x509_fingerprint($pem, 'sha1');
        $der = base64_encode(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem)));

        $this->hb($secret)->assertOk(); // no cert headers: allowed in header mode
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => $sha256, 'X-SSL-Client-Verify' => 'SUCCESS'])->assertOk();
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => strtoupper(implode(':', str_split($sha1, 2)))])->assertOk(); // nginx $ssl_client_fingerprint (SHA-1)
        $this->hb($secret, ['X-Client-Cert' => rawurlencode($pem)])->assertOk(); // nginx $ssl_client_escaped_cert
        $this->hb($secret, ['X-Client-Cert' => $der])->assertOk(); // Traefik passTLSClientCert (base64 DER)

        $this->hb($secret, ['X-Client-Cert-Fingerprint' => str_repeat('ab', 32)])->assertStatus(401);
        $this->hb($secret, ['X-SSL-Client-Verify' => 'FAILED:certificate expired', 'X-Client-Cert-Fingerprint' => $sha256])->assertStatus(401);
        $this->hb($secret, ['X-Client-Cert' => 'not-a-cert'])->assertStatus(401);

        // Another agent's certificate is refused.
        [, $otherPem] = $this->enrollWithCsr();
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => openssl_x509_fingerprint($otherPem, 'sha256')])->assertStatus(401);
    }

    public function test_required_and_off_modes(): void
    {
        [$secret, $pem] = $this->enrollWithCsr();
        config(['security.agent_mtls.mode' => 'required']);
        $this->hb($secret)->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => openssl_x509_fingerprint($pem, 'sha256')])->assertOk();
        config(['security.agent_mtls.mode' => 'off']);
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => str_repeat('00', 32)])->assertOk();
    }

    public function test_renewal_rotates_and_revokes_previous_certificate(): void
    {
        config(['security.agent_mtls.mode' => 'required']);
        [$secret, $old] = $this->enrollWithCsr();
        $oldFp = openssl_x509_fingerprint($old, 'sha256');
        [$csr] = $this->csr('srv');
        app(Tenant::class)->set(null);
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$secret, 'X-Client-Cert-Fingerprint' => $oldFp])
            ->postJson('/agent/v1/certificate/renew', ['csr' => $csr])->assertOk()->assertJsonStructure(['certificate', 'fingerprint', 'expires_at'])->json();
        $this->assertNotSame($oldFp, $r['fingerprint']);
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => $oldFp])->assertStatus(401);
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => $r['fingerprint']])->assertOk();
        $this->assertSame(1, AgentCertificate::allTenants()->whereNotNull('revoked_at')->count());

        // Renewal itself requires the current certificate in required mode.
        $this->withHeaders(['Authorization' => 'Bearer '.$secret, 'X-Client-Cert-Fingerprint' => $oldFp])
            ->postJson('/agent/v1/certificate/renew', ['csr' => $csr])->assertStatus(401);
        // Weak keys are refused.
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        openssl_csr_export(openssl_csr_new(['commonName' => 'x'], $rsa), $weak);
        $this->withHeaders(['Authorization' => 'Bearer '.$secret, 'X-Client-Cert-Fingerprint' => $r['fingerprint']])
            ->postJson('/agent/v1/certificate/renew', ['csr' => $weak])->assertStatus(422);
    }

    public function test_expired_certificate_is_refused(): void
    {
        config(['security.agent_mtls.mode' => 'required']);
        [$secret, $pem] = $this->enrollWithCsr();
        AgentCertificate::allTenants()->update(['expires_at' => now()->subMinute()]);
        $this->hb($secret, ['X-Client-Cert-Fingerprint' => openssl_x509_fingerprint($pem, 'sha256')])->assertStatus(401);
    }
}
