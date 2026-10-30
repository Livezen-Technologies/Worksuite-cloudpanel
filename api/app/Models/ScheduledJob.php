<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ScheduledJob extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'scheduled_jobs';

    protected $guarded = ['id'];

    protected $hidden = ['webhook_token_hash'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'timeout' => 'integer',
            'next_run_at' => 'datetime',
            'alert_email' => 'boolean',
            'alert_webhook' => 'boolean',
        ];
    }
}
