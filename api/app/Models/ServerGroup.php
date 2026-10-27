<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ServerGroup extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'server_groups';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
        ];
    }
}
