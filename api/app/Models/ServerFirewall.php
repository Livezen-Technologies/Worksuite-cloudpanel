<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class ServerFirewall extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'server_firewalls';

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean', 'block' => 'array', 'allow' => 'array', 'ports' => 'array', 'applied_at' => 'datetime'];
}
