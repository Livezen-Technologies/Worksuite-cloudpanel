<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class VolumeMount extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'volume_mounts';

    protected $guarded = ['id'];

    protected $hidden = [];

    public function container() { return $this->belongsTo(Container::class)->withoutGlobalScope('organization'); }

    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
        ];
    }
}
