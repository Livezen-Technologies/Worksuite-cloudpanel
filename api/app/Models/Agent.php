<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'agents';

    protected $guarded = ['id'];

    protected $hidden = ['secret_hash', 'hmac_key'];

    protected function casts(): array
    {
        return [
            'hmac_key' => 'encrypted',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }
}
