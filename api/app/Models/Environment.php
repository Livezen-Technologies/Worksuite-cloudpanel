<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'environments';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
        ];
    }
}
