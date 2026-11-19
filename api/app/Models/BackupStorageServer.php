<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** One SFTP server in the backup storage pool. */
class BackupStorageServer extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'backup_storage_servers';

    protected $guarded = ['id'];

    protected $hidden = ['private_key'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'enabled' => 'boolean',
            'port' => 'integer',
            'total_bytes' => 'integer',
            'used_bytes' => 'integer',
            'free_bytes' => 'integer',
            'checked_at' => 'datetime',
        ];
    }
}
