<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** A CrowdSec ban as last reported by an ingress (platform-wide, not tenant data). */
class FirewallBlockedIp extends Model
{
    use Uuid;

    protected $table = 'firewall_blocked_ips';

    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'expires_at' => 'datetime', 'events' => 'integer'];
}
