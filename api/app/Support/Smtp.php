<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/** Turns the stored SMTP settings (SET-002) into Laravel mailer configuration. */
class Smtp
{
    /**
     * Symfony Mailer knows only "smtp" (plain, upgraded with STARTTLS when the server offers
     * it) and "smtps" (TLS from the start, usually port 465).
     */
    public static function scheme(?string $encryption, ?int $port = null): string
    {
        // The port decides when it is a well-known one: 465 is TLS from the first byte,
        // 587/25/2525 start plain and upgrade with STARTTLS. Picking SSL/TLS for 587
        // otherwise fails with "wrong version number" (Amazon SES, Gmail, Office 365).
        return match (true) {
            $port === 465 => 'smtps',
            in_array($port, [25, 587, 2525], true) => 'smtp',
            default => $encryption === 'ssl' ? 'smtps' : 'smtp',
        };
    }

    /** Mailer config for these settings, or null when no host is set. */
    public static function config(array $smtp): ?array
    {
        if (empty($smtp['host'])) {
            return null;
        }

        return [
            'transport' => 'smtp', 'host' => $smtp['host'], 'port' => (int) ($smtp['port'] ?? 587),
            'username' => $smtp['username'] ?? null,
            'password' => ! empty($smtp['password_encrypted']) ? Crypt::decryptString($smtp['password_encrypted']) : null,
            'scheme' => self::scheme($smtp['encryption'] ?? 'tls', (int) ($smtp['port'] ?? 587)), 'timeout' => 20,
        ];
    }
}
