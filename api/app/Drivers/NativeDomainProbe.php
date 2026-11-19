<?php

namespace App\Drivers;

use App\Contracts\DomainProbe;
use Illuminate\Support\Facades\Http;

/** PDNS: DNS-over-HTTPS for propagation, a TLS handshake for the certificate, a GET for routing. */
class NativeDomainProbe implements DomainProbe
{
    private const TYPES = ['A' => 1, 'CNAME' => 5, 'TXT' => 16, 'AAAA' => 28];

    public function propagation(string $name, string $type): array
    {
        $out = [];
        foreach ((array) config('platform.ingress.resolvers', []) as $label => $url) {
            try {
                $res = Http::timeout(4)->withHeaders(['Accept' => 'application/dns-json'])->get($url, ['name' => $name, 'type' => $type]);
                if (! $res->ok()) {
                    $out[$label] = null;
                    continue;
                }
                $code = self::TYPES[$type] ?? null;
                $out[$label] = collect($res->json('Answer') ?? [])
                    ->filter(fn ($a) => $code === null || (int) ($a['type'] ?? 0) === $code)
                    ->map(fn ($a) => rtrim(trim((string) ($a['data'] ?? ''), '"'), '.'))
                    ->filter()->values()->all();
            } catch (\Throwable) {
                $out[$label] = null;
            }
        }

        return $out;
    }

    public function certificate(string $host): array
    {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client("ssl://{$host}:443", $errno, $error, 5, STREAM_CLIENT_CONNECT, $ctx);
        if (! $socket) {
            return ['ok' => false, 'error' => $error ?: 'Could not open a TLS connection on port 443.'];
        }
        $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        $info = $cert ? openssl_x509_parse($cert) : false;
        if (! $info) {
            return ['ok' => false, 'error' => 'The server did not present a certificate.'];
        }
        $names = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $san) {
            if (str_starts_with(trim($san), 'DNS:')) {
                $names[] = strtolower(substr(trim($san), 4));
            }
        }

        return ['ok' => true, 'names' => $names ?: [strtolower((string) ($info['subject']['CN'] ?? ''))],
            'issuer' => $info['issuer']['O'] ?? $info['issuer']['CN'] ?? null,
            'valid_from' => isset($info['validFrom_time_t']) ? gmdate('c', $info['validFrom_time_t']) : null,
            'expires_at' => isset($info['validTo_time_t']) ? gmdate('c', $info['validTo_time_t']) : null];
    }

    public function https(string $host): array
    {
        try {
            $res = Http::timeout(8)->withoutRedirecting()->withOptions(['verify' => false])->get("https://{$host}/");

            return ['ok' => true, 'status' => $res->status(), 'server' => $res->header('Server') ?: null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
