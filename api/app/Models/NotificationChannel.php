<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'notification_channels';

    protected $guarded = ['id'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'enabled' => 'boolean',
        ];
    }
}
