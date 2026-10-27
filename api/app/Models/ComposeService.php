<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ComposeService extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'compose_services';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'depends_on' => 'array',
        ];
    }
}
