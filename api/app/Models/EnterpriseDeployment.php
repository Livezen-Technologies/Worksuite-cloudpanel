<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A dedicated WorkSuite Server deployed for an enterprise customer through the
 * Cloud Admin service API (docs/ENTERPRISE_API.md). Looked up explicitly by
 * organisation, never through the tenant scope, so the scheduler can advance it.
 */
class EnterpriseDeployment extends Model
{
    use Uuid;

    public const STATUSES = ['queued', 'provisioning', 'awaiting_setup', 'configuring', 'active', 'failed', 'stopped', 'deleted', 'purged'];

    protected $guarded = ['id'];

    protected $hidden = ['callback_secret'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'limits' => 'array',
            'app_status' => 'array',
            'bootstrap_result' => 'array',
            'callback_secret' => 'encrypted',
            'status_checked_at' => 'datetime',
            'activated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class)->withoutGlobalScope('organization'); }
    public function project() { return $this->belongsTo(Project::class)->withoutGlobalScope('organization'); }
}
