<?php

namespace App\Support;

/** Normalises scanner output into {critical, high, medium, low, unknown, total, scanner, vulnerabilities[]} (IMG-003). */
class ScanReport
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    /** Parses the agent's `image.scan` output (Trivy vulnerabilities, see agent/README.md). */
    public static function fromAgent(?array $output): array
    {
        $counts = array_fill_keys(self::SEVERITIES, 0);
        $vulns = [];
        foreach ((array) ($output['vulnerabilities'] ?? []) as $v) {
            $severity = strtolower((string) ($v['severity'] ?? 'unknown'));
            $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'unknown';
            $counts[$severity]++;
            if (count($vulns) < 200) {
                $vulns[] = [
                    'id' => (string) ($v['id'] ?? ''), 'severity' => $severity, 'package' => (string) ($v['package'] ?? ''),
                    'installed' => $v['installed'] ?? null, 'fixed' => $v['fixed'] ?? null, 'title' => $v['title'] ?? null,
                ];
            }
        }
        // The agent caps the list it sends; its per-severity counts cover everything it found.
        foreach ((array) ($output['counts'] ?? []) as $severity => $n) {
            $severity = strtolower((string) $severity);
            if (isset($counts[$severity])) {
                $counts[$severity] = max($counts[$severity], (int) $n);
            }
        }

        return $counts + [
            'total' => (int) ($output['total'] ?? array_sum($counts)),
            'scanner' => (string) ($output['scanner'] ?? 'trivy'),
            'vulnerabilities' => $vulns,
        ];
    }

    /** Whether IMG-003 "block deploys with critical CVEs" applies to this result. */
    public static function blocks(array $result): bool
    {
        return ($result['critical'] ?? 0) > 0 && (bool) Tenant::current()?->setting('security.block_critical_cves');
    }

    public static function summary(array $result): string
    {
        return sprintf('%d critical · %d high · %d medium · %d low', $result['critical'] ?? 0, $result['high'] ?? 0, $result['medium'] ?? 0, $result['low'] ?? 0);
    }
}
