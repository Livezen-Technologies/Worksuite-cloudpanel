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
            'metadata' => 'array',
        ];
    }

    /** A child of a project backup settles the parent's status when it finishes. */
    protected static function booted(): void
    {
        static::updated(function (BackupJob $job) {
            if ($job->parent_id && $job->wasChanged('status')) {
                app(\App\Services\ProjectBackupService::class)->rollup($job->parent_id);
            }
        });
    }

    public function children() { return $this->hasMany(BackupJob::class, 'parent_id'); }

    public function policy() { return $this->belongsTo(BackupPolicy::class, 'backup_policy_id'); }
    public function artifacts() { return $this->hasMany(BackupArtifact::class); }
}
