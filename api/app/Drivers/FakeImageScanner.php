<?php

namespace App\Drivers;

use App\Contracts\ImageScanner;

/** Deterministic fake CVE scanner (counts derived from the digest). */
class FakeImageScanner implements ImageScanner
{
    public function scan(string $reference, ?string $digest): array
    {
        $seed = hexdec(substr(hash('sha256', $digest ?: $reference), 0, 8));
        $critical = str_contains($reference, 'vulnerable') ? 2 : 0;

        return [
            'critical' => $critical,
            'high' => $seed % 3,
            'medium' => $seed % 7,
            'low' => $seed % 11,
            'vulnerabilities' => $critical ? [['id' => 'CVE-2024-0001', 'severity' => 'critical', 'package' => 'openssl']] : [],
        ];
    }
}
