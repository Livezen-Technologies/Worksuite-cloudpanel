<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCertificate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SEC-003: the local agent CA (storage/app/private/ca), CSR signing at enrolment and renewal,
 * and verification of the client certificate forwarded by the TLS-terminating reverse proxy.
 */
class AgentCertificateService
{
    public const MODES = ['off', 'header', 'required'];

    public function __construct(private AuditLogger $audit) {}

    public function caDir(): string
    {
        return storage_path('app/private/ca');
    }

    /** @return array{cert: string, key: string} */
    public function ca(): array
    {
        $dir = $this->caDir();
        if (! is_file("{$dir}/ca.key")) {
            @mkdir($dir, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $opts = $this->opensslOptions('agent_ca');
            $csr = openssl_csr_new(['commonName' => 'Docker Manager Portal Agent CA'], $key, $opts);
            $cert = openssl_csr_sign($csr, null, $key, 3650, $opts, random_int(1, PHP_INT_MAX));
            openssl_pkey_export($key, $keyPem);
            openssl_x509_export($cert, $certPem);
            file_put_contents("{$dir}/ca.key", $keyPem);
            chmod("{$dir}/ca.key", 0600);
            file_put_contents("{$dir}/ca.crt", $certPem);
        }

        return ['cert' => file_get_contents("{$dir}/ca.crt"), 'key' => file_get_contents("{$dir}/ca.key")];
    }

    /** Signs an agent CSR as a client certificate (EKU clientAuth). Returns [pem, AgentCertificate]. */
    public function sign(Agent $agent, string $csrPem): array
    {
        $pub = @openssl_csr_get_public_key($csrPem);
        $details = $pub ? openssl_pkey_get_details($pub) : null;
        if (! $details || ! in_array($details['type'], [OPENSSL_KEYTYPE_EC, OPENSSL_KEYTYPE_RSA], true)
            || ($details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] < 2048)) {
            throw ApiException::validation(['csr' => ['The CSR must contain an EC or RSA (2048+) public key.']]);
        }
        $ca = $this->ca();
        $days = max(1, (int) config('security.agent_mtls.cert_days', 90));
        $serial = random_int(1, PHP_INT_MAX);
        $cert = @openssl_csr_sign($csrPem, $ca['cert'], $ca['key'], $days, $this->opensslOptions('agent_client'), $serial);
        if (! $cert || ! openssl_x509_export($cert, $pem)) {
            throw ApiException::validation(['csr' => ['The CSR could not be signed.']]);
        }
        $info = openssl_x509_parse($pem);
        $row = AgentCertificate::allTenants()->create([
            'organization_id' => $agent->organization_id, 'agent_id' => $agent->id,
            'fingerprint' => openssl_x509_fingerprint($pem, 'sha256'), 'fingerprint_sha1' => openssl_x509_fingerprint($pem, 'sha1'),
            'serial' => (string) $serial, 'certificate' => $pem,
            'not_before' => Carbon::createFromTimestamp($info['validFrom_time_t']), 'expires_at' => Carbon::createFromTimestamp($info['validTo_time_t']),
        ]);

        return [$pem, $row];
    }

    /** Rotation: issue a new certificate and revoke the agent's previous ones. */
    public function renew(Agent $agent, string $csrPem): AgentCertificate
    {
        [, $row] = $this->sign($agent, $csrPem);
        AgentCertificate::allTenants()->where('agent_id', $agent->id)->where('id', '!=', $row->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->audit->log('agent.certificate_renewed', 'servers', $agent->server_id, ['fingerprint' => $row->fingerprint, 'expires_at' => $row->expires_at->toIso8601ZuluString()], $agent->organization_id, null, 'agent');

        return $row;
    }

    /**
     * Extracts the client certificate identity forwarded by the proxy.
     * Returns null when no mTLS header is present, ['invalid' => reason] when present but unusable.
     */
    public function presented(Request $request): ?array
    {
        $cfg = config('security.agent_mtls');
        $verify = $request->header($cfg['verify_header']);
        $fp = $request->header($cfg['fingerprint_header']);
        $raw = $request->header($cfg['cert_header']);
        if ($verify === null && $fp === null && $raw === null) {
            return null;
        }
        if ($verify !== null && strtoupper(trim($verify)) !== 'SUCCESS') {
            return ['invalid' => 'The proxy did not verify the client certificate ('.$verify.').'];
        }
        $out = [];
        if ($raw !== null && $raw !== '') {
            $pem = self::parseForwardedCert($raw);
            if (! $pem) {
                return ['invalid' => 'The forwarded client certificate could not be parsed.'];
            }
            $out['sha256'] = openssl_x509_fingerprint($pem, 'sha256');
        }
        if ($fp !== null && $fp !== '') {
            $norm = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $fp));
            if (strlen($norm) === 64) {
                $out['sha256'] ??= $norm;
                if ($out['sha256'] !== $norm) {
                    return ['invalid' => 'Forwarded certificate and fingerprint disagree.'];
                }
            } elseif (strlen($norm) === 40) {
                $out['sha1'] = $norm;
            } else {
                return ['invalid' => 'Unrecognised client certificate fingerprint.'];
            }
        }

        return $out ?: ['invalid' => 'No client certificate was forwarded.'];
    }

    /** nginx $ssl_client_escaped_cert (URL-encoded PEM), raw PEM, or Traefik's base64 DER (first of a chain). */
    public static function parseForwardedCert(string $raw): ?string
    {
        $value = str_contains($raw, '%') ? rawurldecode($raw) : $raw;
        if (str_contains($value, 'BEGIN CERTIFICATE')) {
            if (! preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $value, $m)) {
                return null;
            }
            $b64 = $m[1];
        } else {
            $b64 = explode(',', $value)[0];
        }
        $der = base64_decode(preg_replace('/\s+/', '', $b64), true);
        if ($der === false || $der === '') {
            return null;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";

        return $pem && @openssl_x509_read($pem) ? $pem : null;
    }

    /** Applies AGENT_MTLS to an agent request; throws 401 when the certificate check fails. */
    public function enforce(Request $request, Agent $agent): void
    {
        $mode = config('security.agent_mtls.mode', 'header');
        if ($mode === 'off') {
            return;
        }
        $presented = $this->presented($request);
        if ($presented === null) {
            if ($mode === 'required') {
                throw new ApiException('unauthenticated', 'A client certificate is required (AGENT_MTLS=required).', 401);
            }

            return;
        }
        if (isset($presented['invalid'])) {
            throw new ApiException('unauthenticated', $presented['invalid'], 401);
        }
        $match = AgentCertificate::allTenants()->where('agent_id', $agent->id)->whereNull('revoked_at')->where('expires_at', '>', now())
            ->where(function ($q) use ($presented) {
                if (isset($presented['sha256'])) {
                    $q->orWhere('fingerprint', $presented['sha256']);
                }
                if (isset($presented['sha1'])) {
                    $q->orWhere('fingerprint_sha1', $presented['sha1']);
                }
            })->exists();
        if (! $match) {
            throw new ApiException('unauthenticated', 'The client certificate does not belong to this agent, or it was revoked or expired.', 401);
        }
    }

    private function opensslOptions(string $section): array
    {
        static $file = null;
        if ($file === null || ! is_file($file)) {
            $file = tempnam(sys_get_temp_dir(), 'dmp-openssl');
            file_put_contents($file, implode("\n", [
                '[ req ]', 'distinguished_name = dn', '[ dn ]',
                '[ agent_client ]', 'basicConstraints = critical,CA:FALSE', 'keyUsage = critical,digitalSignature,keyEncipherment',
                'extendedKeyUsage = clientAuth', 'subjectKeyIdentifier = hash', 'authorityKeyIdentifier = keyid',
                '[ agent_ca ]', 'basicConstraints = critical,CA:TRUE', 'keyUsage = critical,keyCertSign,cRLSign', 'subjectKeyIdentifier = hash', '',
            ]));
        }

        return ['digest_alg' => 'sha256', 'config' => $file, 'x509_extensions' => $section, 'req_extensions' => $section];
    }
}
