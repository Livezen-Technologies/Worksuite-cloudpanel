<?php

namespace App\Support;

/**
 * DNS-011: reads and writes BIND zone files for the record types the panel manages.
 * SOA is the provider's own and is neither imported nor exported; other types are
 * reported as skipped rather than silently dropped.
 */
class BindZone
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    private const CLASSES = ['IN', 'CH', 'HS'];

    /**
     * @return array{records: list<array{line: int, type: string, name: string, content: string, ttl: int, priority: ?int}>, skipped: list<array{line: int, reason: string}>}
     */
    public static function parse(string $text, string $zone): array
    {
        $zone = strtolower(rtrim($zone, '.'));
        $origin = $zone;
        $defaultTtl = null;
        $lastName = $zone;
        $records = [];
        $skipped = [];

        foreach (self::logicalLines($text) as [$line, $raw, $indented]) {
            $tokens = self::tokens($raw);
            if (! $tokens) {
                continue;
            }
            $first = strtoupper($tokens[0]);
            if ($first === '$ORIGIN') {
                $origin = strtolower(rtrim(self::absolute($tokens[1] ?? '', $origin), '.'));
                continue;
            }
            if ($first === '$TTL') {
                $defaultTtl = self::ttl($tokens[1] ?? '');
                continue;
            }
            if (str_starts_with($first, '$')) {
                $skipped[] = ['line' => $line, 'reason' => "{$tokens[0]} is not supported."];
                continue;
            }

            $name = $indented ? $lastName : self::absolute(array_shift($tokens), $origin);
            $lastName = $name;
            $ttl = null;
            // TTL and class may come in either order before the type.
            for ($i = 0; $i < 2 && $tokens; $i++) {
                if (in_array(strtoupper($tokens[0]), self::CLASSES, true)) {
                    array_shift($tokens);
                } elseif (($t = self::ttl($tokens[0])) !== null) {
                    $ttl = $t;
                    array_shift($tokens);
                }
            }
            $type = strtoupper((string) array_shift($tokens));
            if ($type === 'SOA') {
                continue;
            }
            if (! in_array($type, self::TYPES, true)) {
                $skipped[] = ['line' => $line, 'reason' => ($type === '' ? 'No record type' : "{$type} records are not supported").'.'];
                continue;
            }
            $priority = null;
            if (in_array($type, ['MX', 'SRV'], true)) {
                $priority = is_numeric($tokens[0] ?? null) ? (int) array_shift($tokens) : null;
            }
            $content = match ($type) {
                'TXT' => implode('', array_map(fn ($t) => self::unquote($t), $tokens)),
                'CNAME', 'NS', 'MX' => rtrim(self::absolute($tokens[0] ?? '', $origin), '.'),
                'SRV' => implode(' ', [...array_slice($tokens, 0, 2), rtrim(self::absolute($tokens[2] ?? '', $origin), '.')]),
                'CAA' => implode(' ', array_slice($tokens, 0, 2)).' '.(isset($tokens[2]) ? '"'.self::unquote(implode(' ', array_slice($tokens, 2))).'"' : ''),
                default => $tokens[0] ?? '',
            };
            $fqdn = rtrim($name, '.');
            if ($fqdn !== $zone && ! str_ends_with($fqdn, '.'.$zone)) {
                $skipped[] = ['line' => $line, 'reason' => "{$fqdn} is outside {$zone}."];
                continue;
            }
            $records[] = ['line' => $line, 'type' => $type, 'name' => $fqdn, 'content' => trim($content), 'ttl' => $ttl ?? $defaultTtl ?? 1, 'priority' => $priority];
        }

        return ['records' => $records, 'skipped' => $skipped];
    }

    /** @param  iterable<array{type: string, name: string, content: string, ttl: int, priority: ?int}>  $records */
    public static function render(string $zone, iterable $records, ?string $comment = null): string
    {
        $zone = rtrim($zone, '.');
        $out = [];
        if ($comment) {
            $out[] = '; '.$comment;
        }
        $out[] = '$ORIGIN '.$zone.'.';
        $rows = collect($records)->sortBy(fn ($r) => [$r['name'] === $zone ? 0 : 1, $r['name'], array_search($r['type'], self::TYPES, true)]);
        foreach ($rows as $r) {
            $name = $r['name'] === $zone ? '@' : (str_ends_with($r['name'], '.'.$zone) ? substr($r['name'], 0, -strlen($zone) - 1) : $r['name'].'.');
            $ttl = (int) $r['ttl'] === 1 ? 300 : (int) $r['ttl'];   // Auto has no BIND form; 300 is what Cloudflare serves
            $content = match ($r['type']) {
                'TXT' => implode(' ', array_map(fn ($chunk) => '"'.addcslashes($chunk, '"\\').'"', str_split($r['content'], 255) ?: [''])),
                'CNAME', 'NS' => rtrim($r['content'], '.').'.',
                'MX' => (int) ($r['priority'] ?? 10).' '.rtrim($r['content'], '.').'.',
                'SRV' => (int) ($r['priority'] ?? 10).' '.preg_replace('/(\S+?)\.?$/', '$1.', $r['content']),
                default => $r['content'],
            };
            $out[] = sprintf('%-30s %6d IN %-5s %s', $name, $ttl, $r['type'], $content);
        }

        return implode("\n", $out)."\n";
    }

    /** Joins parenthesised continuations and strips comments. @return list<array{int, string, bool}> */
    private static function logicalLines(string $text): array
    {
        $lines = [];
        $buffer = null;
        $start = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $n => $line) {
            $line = self::stripComment($line);
            if ($buffer === null) {
                if (trim($line) === '') {
                    continue;
                }
                $start = $n + 1;
                $indented = (bool) preg_match('/^[ \t]/', $line);
                $buffer = $line;
            } else {
                $buffer .= ' '.$line;
            }
            if (substr_count($buffer, '(') <= substr_count($buffer, ')')) {
                $lines[] = [$start, str_replace(['(', ')'], ' ', $buffer), $indented];
                $buffer = null;
            }
        }

        return $lines;
    }

    private static function stripComment(string $line): string
    {
        $quoted = false;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '"') {
                $quoted = ! $quoted;
            } elseif ($c === ';' && ! $quoted) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    /** Splits on whitespace, keeping "quoted strings" (with their quotes) as one token. */
    private static function tokens(string $line): array
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|\S+/', $line, $m);

        return $m[0];
    }

    private static function unquote(string $t): string
    {
        return str_starts_with($t, '"') ? stripcslashes(substr($t, 1, -1)) : $t;
    }

    private static function absolute(string $name, string $origin): string
    {
        $name = strtolower($name);
        if ($name === '@' || $name === '') {
            return $origin.'.';
        }

        return str_ends_with($name, '.') ? $name : $name.'.'.$origin.'.';
    }

    /** "3600", "1h", "1d2h" to seconds; null when not a TTL. */
    private static function ttl(string $t): ?int
    {
        if (ctype_digit($t)) {
            return (int) $t;
        }
        if (! preg_match('/^(\d+[smhdw])+$/i', $t)) {
            return null;
        }
        preg_match_all('/(\d+)([smhdw])/i', $t, $m, PREG_SET_ORDER);

        return array_sum(array_map(fn ($p) => (int) $p[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][strtolower($p[2])], $m));
    }
}
