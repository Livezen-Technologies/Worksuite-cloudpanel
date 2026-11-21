<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** One update run of an enterprise deployment (EnterpriseUpdateService). Looked up explicitly, never via the tenant scope. */
class EnterpriseUpdate extends Model
{
    use Uuid;

    public const ACTIVE = ['scheduled', 'backing_up', 'deploying', 'rolling_back'];

    public const TERMINAL = ['succeeded', 'failed', 'rolled_back', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'log' => 'array',
            'job_ids' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'deploy_started_at' => 'datetime',
            'rollback_started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function deployment() { return $this->belongsTo(EnterpriseDeployment::class, 'enterprise_deployment_id'); }
}
