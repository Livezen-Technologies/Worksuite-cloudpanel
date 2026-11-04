<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** A WorkSuite Business / CMS deployment requested by a partner. Looked up by client, never by tenant scope. */
class PartnerDeployment extends Model
{
    use Uuid;

    public const STATUSES = ['provisioning', 'running', 'stopped', 'suspended', 'failed', 'terminated'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'storage_gb' => 'integer',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function client() { return $this->belongsTo(PartnerClient::class, 'partner_client_id'); }
    public function account() { return $this->belongsTo(PartnerAccount::class, 'partner_account_id'); }
    public function application() { return $this->belongsTo(Application::class)->withoutGlobalScope('organization')->withTrashed(); }
}
