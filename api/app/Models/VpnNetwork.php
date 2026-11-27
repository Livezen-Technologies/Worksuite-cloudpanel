<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** VPN phase 1: a client-access VPN network (WireGuard or OpenVPN) served by one gateway server. */
class VpnNetwork extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['private_dns' => 'boolean', 'port' => 'integer'];
    }

    public function gateway() { return $this->belongsTo(Server::class, 'gateway_server_id'); }
}
