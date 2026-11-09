<?php

namespace App\Support;

/**
 * An https URL whose host resolves only to public addresses, so a user-supplied
 * address can't point the panel at its own network. (Resolution is checked when the
 * URL is saved; Http calls resolve again, so this narrows rather than removes rebinding.)
 */
final class PublicUrl
{
    public static function check(string $url): ?string
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return 'Use an https:// address.';
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return 'That host name does not resolve.';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That address points to a private or reserved network.';
            }
        }

        return null;
    }
}
