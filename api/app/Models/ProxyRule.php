<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ProxyRule extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'proxy_rules';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }
}
