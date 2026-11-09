<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** CFT-1: a server's Cloudflare Tunnel; the token is encrypted and never returned. */
class CloudflareTunnel extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'hostnames' => 'array', 'last_synced_at' => 'datetime', 'connections' => 'integer', 'close_ports' => 'boolean', 'ports_closed' => 'boolean'];
    }

    public function server() { return $this->belongsTo(Server::class); }
    public function provider() { return $this->belongsTo(DnsProviderAccount::class, 'dns_provider_id'); }
}
