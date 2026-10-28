<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Passkey extends Model
{
    use Uuid;

    protected $table = 'passkeys';

    protected $guarded = ['id'];

    protected $hidden = ['public_key', 'credential_hash'];

    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'backup_eligible' => 'boolean',
            'sign_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }
}
