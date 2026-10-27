<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use BelongsToOrganization, Uuid;

    protected $guarded = ['id'];

    protected $appends = ['status', 'container_count'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'roles' => 'array',
            'maintenance' => 'boolean',
            'shared_pool' => 'boolean',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function agent() { return $this->hasOne(Agent::class); }
    public function containers() { return $this->hasMany(Container::class); }
    public function group() { return $this->belongsTo(ServerGroup::class, 'server_group_id'); }

    /**
     * Status computed on read from heartbeat age (offline after 90 s) so it can
     * never be stale: pending|online|warning|maintenance|offline.
     */
    public function getStatusAttribute(): string
    {
        if ($this->maintenance) {
            return 'maintenance';
        }
        if (! $this->last_heartbeat_at) {
            return 'pending';
        }
        if ($this->last_heartbeat_at->lt(now()->subSeconds(config('platform.offline_after_seconds')))) {
            return 'offline';
        }
        if ($this->docker_error || max($this->cpu_pct ?? 0, $this->ram_pct ?? 0) > 90 || ($this->disk_pct ?? 0) > 85) {
            return 'warning';
        }

        return 'online';
    }

    public function getContainerCountAttribute(): int
    {
        return array_key_exists('containers_count', $this->attributes)
            ? (int) $this->attributes['containers_count']
            : $this->containers()->count();
    }

    /** Server roles: "app" (runs workloads, the default) and "builder" (runs untrusted image builds, SRS 2.4). */
    public function getRolesAttribute($value): array
    {
        $roles = is_string($value) ? json_decode($value, true) : $value;

        return $roles ?: ['app'];
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isOnline(): bool
    {
        return in_array($this->status, ['online', 'warning'], true);
    }
}
