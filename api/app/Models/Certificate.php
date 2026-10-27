<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'certificates';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'names' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
