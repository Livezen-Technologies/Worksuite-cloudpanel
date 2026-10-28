<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class EnvVar extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'env_vars';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'secret' => 'boolean',
        ];
    }
}
