<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** UAI: one AI or MCP tool call. */
class AiAction extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'result' => 'array', 'decided_at' => 'datetime'];
    }
}
