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

    /** DNS-013: the organisation's zone that holds this host name (the longest matching zone wins). */
    public static function forDomain(string $name, ?string $organizationId): ?self
    {
        if (! $organizationId) {
            return null;
        }
        $name = strtolower(ltrim(rtrim($name, '.'), '*.'));
        $candidates = [];
        for ($parts = explode('.', $name); count($parts) >= 2; array_shift($parts)) {
            $candidates[] = implode('.', $parts);
        }

        return static::allTenants()->with(['provider' => fn ($q) => $q->withoutGlobalScope('organization')])->where('organization_id', $organizationId)->whereIn('name', $candidates)
            ->get()->sortByDesc(fn ($z) => strlen($z->name))->first();
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
