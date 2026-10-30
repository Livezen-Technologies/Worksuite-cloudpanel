<?php

namespace App\Models;

use App\Contracts\DnsProvider;
use App\Drivers\CloudflareDnsProvider;
use App\Drivers\PowerDnsProvider;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** DNS-001: a connected provider account. Credentials are encrypted and never returned. */
class DnsProviderAccount extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'dns_providers';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }

    public function driver(): DnsProvider
    {
        $factory = app()->bound('dns.provider.factory') ? app('dns.provider.factory') : null;
        if ($factory) {
            return $factory($this);
        }

        return match ($this->type) {
            'cloudflare' => new CloudflareDnsProvider((string) ($this->credentials['token'] ?? ''), $this->credentials['account_id'] ?? null),
            'powerdns' => new PowerDnsProvider((string) ($this->credentials['api_url'] ?? ''), (string) ($this->credentials['api_key'] ?? ''),
                (string) ($this->credentials['server_id'] ?? 'localhost'), (array) ($this->credentials['nameservers'] ?? [])),
        };
    }
}
