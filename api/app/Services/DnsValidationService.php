<?php

namespace App\Services;

use App\Contracts\DnsResolver;
use App\Contracts\DomainProbe;
use App\Models\Application;
use App\Models\DnsCheck;
use App\Models\Domain;

/**
 * PDNS: "Validate DNS". Runs every check on the server, in real time, and reports each one on its
 * own with what was expected and what was found: ownership, resolution, ingress address, CNAME
 * target, propagation across public resolvers, certificate, HTTPS routing and the app behind it.
 * Each check is pass | warn | fail | skip; the domain is healthy when nothing fails.
 */
class DnsValidationService
{
    public function __construct(private DnsResolver $dns, private DomainProbe $probe, private DomainService $domains) {}

    public function validate(Domain $domain): array
    {
        // The status update and certificate issuance the 2-minute re-check would do, now.
        $statuses = rescue(fn () => $this->domains->verify($domain), [], report: false);
        $domain->refresh();
        $name = ltrim($domain->name, '*.');
        $expected = $this->domains->expectedRecords($domain);
        $records = array_map(function ($r) {
            $found = array_map(fn ($v) => rtrim($v, '.'), $this->lookup(ltrim($r['name'], '*.'), $r['type']));
            $ok = in_array(rtrim($r['value'], '.'), $found, true);

            return $r + ['actual' => $found, 'status' => $ok ? 'pass' : 'fail'];
        }, $expected);

        $a = $this->lookup($name, 'A');
        $aaaa = $this->lookup($name, 'AAAA');
        $cname = array_map(fn ($v) => rtrim($v, '.'), $this->lookup($name, 'CNAME'));
        $ingressIps = $this->domains->ingressIps();
        $host = $this->domains->ingressHostname();
        // An Enterprise deployment's domain must point at the edge servers, not the cluster ingress.
        $edges = $this->domains->enterpriseEdgeIps($domain);
        if ($edges) {
            $ingressIps = $edges;
        }
        $routing = collect($expected)->first(fn ($r) => in_array($r['type'], ['A', 'AAAA', 'CNAME'], true) && $r['name'] === $domain->name);
        $app = $domain->application_id ? Application::find($domain->application_id) : null;
        $platform = (bool) $domain->platform_subdomain;

        $checks = [];
        $checks[] = $this->ownership($domain, $platform);
        $checks[] = $platform ? $this->skip('resolution', 'DNS resolution', 'Served by the platform zone.')
            : $this->check('resolution', 'DNS resolution (A / AAAA / CNAME)', (bool) ($a || $aaaa || $cname), 'A, AAAA or CNAME record',
                $this->describe(['A' => $a, 'AAAA' => $aaaa, 'CNAME' => $cname]), 'The name does not resolve yet. Create the records below at your DNS provider.');
        $checks[] = $platform ? $this->skip('ingress_ip', 'Points at the cluster ingress', 'Served by the platform zone.') : $this->ingressCheck($a, $ingressIps, $app);
        $checks[] = $routing && $routing['type'] === 'CNAME'
            ? $this->check('cname_target', 'CNAME target', in_array(rtrim($routing['value'], '.'), $cname, true), $routing['value'], $cname ? implode(', ', $cname) : 'no CNAME',
                $cname ? 'The CNAME points somewhere else. Change it to '.$routing['value'].'.' : 'No CNAME record yet.')
            : $this->skip('cname_target', 'CNAME target', 'This domain uses A records, not a CNAME.');
        $checks[] = $edges ? $this->skip('ingress_endpoint', 'Ingress endpoint', 'Enterprise domains point at the edge servers directly.') : $this->ingressEndpoint($host, $ingressIps);
        $checks[] = $platform || ! $routing ? $this->skip('propagation', 'Propagation across public resolvers', 'Nothing to propagate.') : $this->propagation($routing);
        $checks[] = $this->certificate($domain);
        $checks[] = $this->https($domain);
        $checks[] = $this->backend($app);

        $ok = collect($checks)->doesntContain(fn ($c) => $c['status'] === 'fail');
        DnsCheck::create(['domain_id' => $domain->id, 'kind' => 'validation', 'records' => $records, 'checks' => $checks, 'ok' => $ok, 'created_at' => now()]);

        return [
            'domain_id' => $domain->id, 'domain' => $domain->name, 'ok' => $ok, 'checked_at' => now()->toIso8601ZuluString(),
            'checks' => $checks, 'records' => $records,
            'dns_status' => $statuses['dns_status'] ?? $domain->dns_status, 'https_status' => $domain->https_status,
            'ingress' => ['hostname' => $edges ? null : $host, 'ips' => $ingressIps],
        ];
    }

    private function lookup(string $name, string $type): array
    {
        return rescue(fn () => $this->dns->lookup($name, $type), [], report: false);
    }

    private function ownership(Domain $domain, bool $platform): array
    {
        if ($platform || ! $domain->ownership_token) {
            return $this->skip('ownership', 'Domain ownership (TXT)', $platform ? 'Livezen Cloud subdomains need no ownership check.' : 'Added before ownership checks; not required.');
        }
        $name = $this->domains->ownershipName($domain);
        $values = $this->lookup($name, 'TXT');

        return $this->check('ownership', 'Domain ownership (TXT)', in_array($this->domains->ownershipValue($domain), $values, true),
            "{$name} TXT ".$this->domains->ownershipValue($domain), $values ? implode(', ', $values) : 'no TXT record',
            'Add the TXT record shown. The domain does not go live until ownership is proven.');
    }

    private function ingressCheck(array $a, array $ingressIps, ?Application $app): array
    {
        if (! $a) {
            return $this->check('ingress_ip', 'Points at the cluster ingress', false, implode(', ', $ingressIps), 'no address', 'The name has no IPv4 address yet.');
        }
        $stray = array_values(array_diff($a, $ingressIps));
        $node = $app?->server?->ip;
        $message = $node && in_array($node, $stray, true)
            ? "It points at the server running the app ({$node}), not the ingress. Point it at the ingress so moving the app never breaks your site."
            : 'It points at an address that is not the platform ingress.';

        return $this->check('ingress_ip', 'Points at the cluster ingress', $stray === [], implode(', ', $ingressIps), implode(', ', $a), $message);
    }

    private function ingressEndpoint(?string $host, array $ips): array
    {
        if (! $host) {
            return ['key' => 'ingress_endpoint', 'label' => 'Ingress endpoint', 'status' => 'warn', 'expected' => 'an ingress host name', 'actual' => implode(', ', $ips),
                'message' => 'No ingress host name is configured (PLATFORM_INGRESS_HOSTNAME), so subdomains get A records instead of a CNAME.'];
        }
        $resolved = $this->lookup($host, 'A');
        $ok = $resolved && ! array_diff($resolved, $ips);

        return ['key' => 'ingress_endpoint', 'label' => 'Ingress endpoint', 'status' => $ok ? 'pass' : 'warn', 'expected' => "{$host} → ".implode(', ', $ips),
            'actual' => $resolved ? implode(', ', $resolved) : 'does not resolve', 'message' => $ok ? null : 'The ingress host name does not resolve to the ingress addresses. Tell support.'];
    }

    private function propagation(array $routing): array
    {
        $want = rtrim($routing['value'], '.');
        $answers = $this->probe->propagation(ltrim($routing['name'], '*.'), $routing['type']);
        $per = [];
        foreach ($answers as $resolver => $values) {
            $per[] = ['resolver' => $resolver, 'values' => $values, 'status' => $values === null ? 'unknown' : (in_array($want, $values, true) ? 'pass' : 'fail')];
        }
        $asked = collect($per)->where('status', '!=', 'unknown');
        $good = $asked->where('status', 'pass')->count();
        $status = $asked->isEmpty() ? 'warn' : ($good === $asked->count() ? 'pass' : ($good > 0 ? 'warn' : 'fail'));

        return ['key' => 'propagation', 'label' => 'Propagation across public resolvers', 'status' => $status, 'expected' => "{$routing['type']} {$want}",
            'actual' => "{$good} of ".count($per).' resolvers', 'resolvers' => $per,
            'message' => match ($status) { 'pass' => null, 'warn' => 'Some resolvers still have the old answer. DNS changes can take up to an hour to spread.', default => 'No public resolver sees the record yet.' }];
    }

    private function certificate(Domain $domain): array
    {
        $c = $this->probe->certificate(ltrim($domain->name, '*.'));
        if (! ($c['ok'] ?? false)) {
            return ['key' => 'ssl', 'label' => 'SSL certificate', 'status' => 'fail', 'expected' => 'a valid certificate for '.$domain->name, 'actual' => $c['error'] ?? 'none',
                'message' => $domain->dns_status === 'verified' ? 'The certificate is not being served yet. It is issued automatically once DNS is verified.' : 'Issued automatically once DNS is verified.'];
        }
        $names = $c['names'] ?? [];
        $covers = collect($names)->contains(fn ($n) => $n === $domain->name || (str_starts_with($n, '*.') && substr_count($domain->name, '.') === substr_count($n, '.') && str_ends_with($domain->name, substr($n, 1))));
        $expires = isset($c['expires_at']) ? \Carbon\Carbon::parse($c['expires_at']) : null;
        $status = ! $covers || ($expires && $expires->isPast()) ? 'fail' : ($expires && $expires->lt(now()->addDays(14)) ? 'warn' : 'pass');

        return ['key' => 'ssl', 'label' => 'SSL certificate', 'status' => $status, 'expected' => 'valid for '.$domain->name, 'actual' => implode(', ', $names).($expires ? ' · expires '.$expires->toDateString() : ''),
            'issuer' => $c['issuer'] ?? null, 'expires_at' => $expires?->toIso8601ZuluString(),
            'message' => match (true) { ! $covers => 'The certificate served is for another name.', $expires && $expires->isPast() => 'The certificate has expired.', $status === 'warn' => 'The certificate expires within 14 days.', default => null }];
    }

    private function https(Domain $domain): array
    {
        $r = $this->probe->https(ltrim($domain->name, '*.'));
        $code = (int) ($r['status'] ?? 0);
        $ok = ($r['ok'] ?? false) && $code > 0 && $code < 500 && $code !== 404;

        return ['key' => 'https', 'label' => 'HTTPS routing', 'status' => $ok ? 'pass' : 'fail', 'expected' => 'a response from your app', 'actual' => $code ? "HTTP {$code}" : ($r['error'] ?? 'no response'),
            'message' => $ok ? null : ($code === 404 ? 'The ingress answered 404: no route for this name yet.' : ($code >= 500 ? 'The ingress could not reach the app.' : 'Nothing answered on HTTPS.'))];
    }

    private function backend(?Application $app): array
    {
        if (! $app) {
            return ['key' => 'backend', 'label' => 'Application health', 'status' => 'fail', 'expected' => 'a target app', 'actual' => 'none', 'message' => 'Choose the app this domain routes to.'];
        }
        $ok = $app->status === 'running';

        return ['key' => 'backend', 'label' => 'Application health', 'status' => $ok ? 'pass' : 'fail', 'expected' => "{$app->name} running", 'actual' => "{$app->name} ".($app->status ?? 'unknown'),
            'message' => $ok ? null : 'The app is not running, so the domain has nothing to show.'];
    }

    private function check(string $key, string $label, bool $ok, string $expected, string $actual, ?string $failMessage = null): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'expected' => $expected, 'actual' => $actual, 'message' => $ok ? null : $failMessage];
    }

    private function skip(string $key, string $label, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'status' => 'skip', 'expected' => null, 'actual' => null, 'message' => $message];
    }

    private function describe(array $byType): string
    {
        $parts = [];
        foreach ($byType as $type => $values) {
            if ($values) {
                $parts[] = $type.' '.implode(', ', array_map(fn ($v) => rtrim($v, '.'), $values));
            }
        }

        return $parts ? implode(' · ', $parts) : 'nothing';
    }
}
