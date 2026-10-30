<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** AV-10: a code file added, changed or removed outside a deployment. */
class IntegrityChange extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
