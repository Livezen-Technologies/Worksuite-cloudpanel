<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'domains';

    protected $guarded = ['id'];

    protected $hidden = [];

    /** PDNS: what the last zone publish did, for the response that created the domain. Not a column. */
    public ?array $publishedRecords = null;

    protected static function booted(): void
    {
        $sync = fn (Domain $d) => app(\App\Services\CloudflareTunnelService::class)->domainChanged($d);
        static::created($sync);
        static::deleted($sync);
    }

    protected function casts(): array
    {
        return [
            'wildcard' => 'boolean',
            'www_redirect' => 'boolean',
            'platform_subdomain' => 'boolean',
            'options' => 'array',
            'dns_checked_at' => 'datetime',
            'is_primary' => 'boolean',
            'ownership_verified_at' => 'datetime',
        ];
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function certificate() { return $this->hasOne(Certificate::class)->orderByDesc('created_at'); }
    public function dnsChecks() { return $this->hasMany(DnsCheck::class); }
}
