<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** CLN-4: one cleanup on a server, what triggered it and what it freed. */
class CleanupRun extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'disk_pct' => 'float', 'freed_bytes' => 'integer'];
    }

    public function server() { return $this->belongsTo(Server::class); }
}
