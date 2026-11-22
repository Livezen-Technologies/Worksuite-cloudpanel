<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** XNODE-3b: the edge-hop CA (kind=ca) and the app nodes' server certificates (kind=node). */
class EdgeTlsCert extends Model
{
    use Uuid;

    protected $table = 'edge_tls_certs';

    protected $guarded = ['id'];

    protected $hidden = ['key_encrypted'];

    protected function casts(): array
    {
        return [
            'key_encrypted' => 'encrypted',
            'not_after' => 'datetime',
            'ports' => 'array',
            'live_ports' => 'array',
        ];
    }
}
