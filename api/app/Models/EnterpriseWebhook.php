<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** Outbox row: one signed status event to deliver to a deployment's callback URL. */
class EnterpriseWebhook extends Model
{
    use Uuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function deployment() { return $this->belongsTo(EnterpriseDeployment::class, 'enterprise_deployment_id'); }
}
