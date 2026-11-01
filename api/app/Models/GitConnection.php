<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class GitConnection extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'git_connections';

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'checked_at' => 'datetime'];
    }
}
