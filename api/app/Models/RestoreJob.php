<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class RestoreJob extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'restore_jobs';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [

        ];
    }

    public function artifact() { return $this->belongsTo(BackupArtifact::class, 'backup_artifact_id'); }
}
