<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class DnsCheck extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'dns_checks';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'records' => 'array',
            'ok' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public const UPDATED_AT = null;
}
