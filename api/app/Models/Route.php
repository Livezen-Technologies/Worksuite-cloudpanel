<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'routes';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
        ];
    }
}
