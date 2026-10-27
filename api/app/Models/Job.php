<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** An asynchronous, idempotent operation with stages and progress. */
class Job extends Model
{
    use BelongsToOrganization, Uuid;

    protected $table = 'jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function commands() { return $this->hasMany(AgentCommand::class); }
    public function attempts() { return $this->hasMany(JobAttempt::class); }

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled'], true);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'progress' => (int) $this->progress,
            'stages' => $this->stages ?? [],
            'result' => $this->result,
            'error' => $this->error,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
        ];
    }
}
