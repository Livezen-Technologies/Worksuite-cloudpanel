<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class DnsRecord extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ttl' => 'integer', 'priority' => 'integer', 'proxied' => 'boolean'];
    }
}
