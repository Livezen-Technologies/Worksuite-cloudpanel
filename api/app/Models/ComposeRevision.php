<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ComposeRevision extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'compose_revisions';

    protected $guarded = ['id'];

    protected $hidden = ['env'];

    protected function casts(): array
    {
        return [
            'env' => 'encrypted:array',
            'created_at' => 'datetime',
        ];
    }

    public const UPDATED_AT = null;
}
