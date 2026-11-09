<?php

namespace App\Support;

/**
 * CRON-1: a five-field cron expression in plain words, for the schedule preview.
 * Covers the shapes people write; anything else falls back to the expression.
 */
class CronDescription
{
    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    private const MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public static function describe(string $cron): string
    {
        $aliases = ['@yearly' => '0 0 1 1 *', '@annually' => '0 0 1 1 *', '@monthly' => '0 0 1 * *', '@weekly' => '0 0 * * 0', '@daily' => '0 0 * * *', '@midnight' => '0 0 * * *', '@hourly' => '0 * * * *'];
        $parts = preg_split('/\s+/', trim($aliases[strtolower(trim($cron))] ?? $cron));
        if (count($parts) !== 5) {
            return $cron;
        }
        [$min, $hour, $dom, $mon, $dow] = $parts;

        if ($hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            if ($min === '*') {
                return 'Every minute';
            }
            if (preg_match('/^\*\/(\d+)$/', $min, $m)) {
                return "Every {$m[1]} minutes";
            }
            if (ctype_digit($min)) {
                return (int) $min === 0 ? 'Every hour' : "Every hour at minute {$min}";
            }
        }
        if (ctype_digit($min) && preg_match('/^\*\/(\d+)$/', $hour, $m) && $dom === '*' && $mon === '*' && $dow === '*') {
            return "Every {$m[1]} hours at minute {$min}";
        }
        if (! ctype_digit($min) || ! preg_match('/^\d+(,\d+)*$/', $hour)) {
            return $cron;
        }
        $at = 'at '.implode(', ', array_map(fn ($h) => sprintf('%02d:%02d', (int) $h, (int) $min), explode(',', $hour)));

        $when = match (true) {
            $dom === '*' && $mon === '*' && $dow === '*' => 'Every day',
            $dom === '*' && $mon === '*' && $dow === '1-5' => 'Every weekday',
            $dom === '*' && $mon === '*' && preg_match('/^[0-7](,[0-7])*$/', $dow) === 1 => 'Every '.implode(', ', array_map(fn ($d) => self::DAYS[(int) $d % 7], explode(',', $dow))),
            ctype_digit($dom) && $mon === '*' && $dow === '*' => 'On day '.$dom.' of every month',
            ctype_digit($dom) && ctype_digit($mon) && $dow === '*' && isset(self::MONTHS[(int) $mon]) => 'Every year on '.self::MONTHS[(int) $mon].' '.$dom,
            default => null,
        };

        return $when ? "{$when} {$at}" : $cron;
    }
}
