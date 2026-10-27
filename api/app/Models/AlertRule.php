<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'alert_rules';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'channel_ids' => 'array',
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'threshold' => 'float',
        ];
    }
}
