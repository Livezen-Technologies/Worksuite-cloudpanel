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

    protected function casts(): array
    {
        return [
            'wildcard' => 'boolean',
            'www_redirect' => 'boolean',
            'platform_subdomain' => 'boolean',
            'options' => 'array',
            'dns_checked_at' => 'datetime',
        ];
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function certificate() { return $this->hasOne(Certificate::class)->latestOfMany(); }
    public function dnsChecks() { return $this->hasMany(DnsCheck::class); }
}
