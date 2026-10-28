<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'approvals';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
