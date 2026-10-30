<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Volume extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'volumes';

    protected $guarded = ['id'];

    public function getConsumersAttribute(): int
    {
        return (int) ($this->attributes['used_by'] ?? 0);
    }

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'integrity_baseline_at' => 'datetime',
            'integrity_checked_at' => 'datetime',
        ];
    }

    /** 'consumers' is what the volumes table shows as "Used by". */
    protected $appends = ['has_backup_policy', 'consumers'];
    public function server() { return $this->belongsTo(Server::class); }
    public function mounts() { return $this->hasMany(VolumeMount::class); }
    public function getHasBackupPolicyAttribute(): bool
    {
        return BackupPolicy::where('target_type', 'volume')->where('target_id', $this->id)->exists();
    }
}
