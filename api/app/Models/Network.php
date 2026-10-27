<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'networks';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'internal' => 'boolean',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }
}
