<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** PNET-3: an administrator's device on the private network. Its private key is shown once and never stored. */
class VpnPeer extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_handshake_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
