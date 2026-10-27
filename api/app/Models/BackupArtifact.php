<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class BackupArtifact extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'backup_artifacts';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'encrypted' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function backupJob() { return $this->belongsTo(BackupJob::class); }
}
