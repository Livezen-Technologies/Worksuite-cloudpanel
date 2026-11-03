<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** SWARM: one Docker Swarm. Join tokens are encrypted and never returned by the API. */
class SwarmCluster extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['worker_token', 'manager_token'];

    protected function casts(): array
    {
        return ['worker_token' => 'encrypted', 'manager_token' => 'encrypted', 'snapshot' => 'array', 'refreshed_at' => 'datetime'];
    }

    public function manager() { return $this->belongsTo(Server::class, 'manager_server_id'); }
}
