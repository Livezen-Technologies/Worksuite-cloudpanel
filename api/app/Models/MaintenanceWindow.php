<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class MaintenanceWindow extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'maintenance_windows';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'suppress_alerts' => 'boolean',
        ];
    }
}
