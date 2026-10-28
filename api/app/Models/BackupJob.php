<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class BackupJob extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'backup_jobs';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function policy() { return $this->belongsTo(BackupPolicy::class, 'backup_policy_id'); }
    public function artifacts() { return $this->hasMany(BackupArtifact::class); }
}
