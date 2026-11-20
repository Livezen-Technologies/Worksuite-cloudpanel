<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** One ISP (autonomous system) rule. Matched by CrowdSec through its GeoLite2 ASN data. */
class FirewallIsp extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'firewall_isps';

    protected $guarded = ['id'];

    protected $casts = ['asn' => 'integer'];
}
