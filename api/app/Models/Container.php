<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Container extends Model
{
    use BelongsToOrganization, Uuid;

    protected $guarded = ['id'];

    protected $hidden = ['last_logs'];

    protected $appends = ['uptime_seconds'];

    protected function casts(): array
    {
        return [
            'ports' => 'array',
            'spec' => 'array',
            'labels' => 'array',
            'started_at' => 'datetime',
            'docker_created_at' => 'datetime',
            'logs_fetched_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }
    public function application() { return $this->belongsTo(Application::class); }

    public function getUptimeSecondsAttribute(): ?int
    {
        return $this->state === 'running' && $this->started_at ? (int) $this->started_at->diffInSeconds(now()) : null;
    }

    /** Reference the agent understands: the Docker id once known, else the name. */
    public function dockerRef(): string
    {
        return $this->docker_id ?: $this->name;
    }
}
