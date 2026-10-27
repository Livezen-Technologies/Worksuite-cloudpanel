<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class BackupPolicy extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'backup_policies';

    protected $guarded = ['id'];

    protected $hidden = ['destination_config', 'recovery_key'];

    protected function casts(): array
    {
        return [
            'destination_config' => 'encrypted:array',
            'recovery_key' => 'encrypted',
            'encrypted' => 'boolean',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
