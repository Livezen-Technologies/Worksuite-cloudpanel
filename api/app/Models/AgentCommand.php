<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** A typed, signed command queued for one server's agent (id = operation id). */
class AgentCommand extends Model
{
    use BelongsToOrganization, Uuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array', // may carry secrets / registry auth
            'output' => 'array',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    // Unscoped like Container::server(): a command for an app on a shared-pool server is applied in the app's organisation, not the server's.
    public function server() { return $this->belongsTo(Server::class)->withoutGlobalScope('organization'); }
    public function job() { return $this->belongsTo(Job::class); }

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }
}
