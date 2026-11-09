<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** Outbox row: one signed event to deliver to a partner's webhook URL. */
class PartnerWebhook extends Model
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

    public function client() { return $this->belongsTo(PartnerClient::class, 'partner_client_id'); }
}
