<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** FW-011. One allow-list or block-list entry: an address or a CIDR range. */
class FirewallIpList extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'firewall_ip_lists';

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime'];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** "203.0.113.7" or "203.0.113.0/24" normalised to a CIDR, or null if invalid. */
    public static function normalise(string $value): ?string
    {
        $value = trim($value);
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        if ($bits === null) {
            return $ip.'/'.$max;
        }
        if (! ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }

        return $ip.'/'.(int) $bits;
    }

    /** Whether an address falls inside a CIDR range. */
    public static function contains(string $cidr, string $ip): bool
    {
        [$net, $bits] = explode('/', $cidr) + [1 => null];
        $a = @inet_pton($net);
        $b = @inet_pton($ip);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($a[$bytes]) & ord($mask)) === (ord($b[$bytes]) & ord($mask));
    }
}
