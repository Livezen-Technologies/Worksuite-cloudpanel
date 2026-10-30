<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class DnsZone extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nameservers' => 'array', 'last_synced_at' => 'datetime', 'records_count' => 'integer', 'ds_records' => 'array'];
    }

    public function provider()
    {
        return $this->belongsTo(DnsProviderAccount::class, 'dns_provider_id');
    }

    public function records()
    {
        return $this->hasMany(DnsRecord::class);
    }
}
