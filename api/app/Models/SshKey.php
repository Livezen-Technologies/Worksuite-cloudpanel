<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class SshKey extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'ssh_keys';

    protected $guarded = ['id'];

    protected $hidden = ['private_key'];

    protected function casts(): array
    {
        return ['private_key' => 'encrypted', 'last_used_at' => 'datetime'];
    }
}
