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

    /** The run log can be long; it is fetched per job (GET backup-jobs/{id}/log). */
    protected $hidden = ['log'];

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
