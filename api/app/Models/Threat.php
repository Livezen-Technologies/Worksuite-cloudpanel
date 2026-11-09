<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** AV-4: one infected file found by a scan. */
class Threat extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime', 'resolved_at' => 'datetime', 'size' => 'integer'];
    }

    public function server()
    {
        return $this->belongsTo(Server::class)->withoutGlobalScope('organization');
    }
}
