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

    /** CRON: the server's runner follows every change to its jobs. */
    protected static function booted(): void
    {
        $sync = fn (ScheduledJob $j) => $j->trigger === 'cron' && in_array($j->action, \App\Services\ScheduledJobService::ENGINE, true)
            ? app(\App\Services\ScheduledJobService::class)->syncRunnerFor($j) : null;
        static::saved(fn (ScheduledJob $j) => $j->wasChanged(['cron', 'timezone', 'enabled', 'params', 'run_mode', 'timeout', 'server_id', 'application_id']) || $j->wasRecentlyCreated ? $sync($j) : null);
        static::deleted($sync);
    }
}
