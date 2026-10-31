<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use BelongsToOrganization, Uuid;

    protected $guarded = ['id'];

    protected $appends = ['status', 'container_count', 'health_score'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'security' => 'array',
            'roles' => 'array',
            'cleanup' => 'array',
            'mesh_status' => 'array',
            'mesh_joined_at' => 'datetime',
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

    /**
     * DASH-00x. One 0-100 number for "how well is this server doing", so the
     * fleet can be ranked and bucketed. It deliberately reuses the same
     * thresholds as getStatusAttribute() above, so a server the panel calls
     * "warning" never scores as if it were healthy.
     *
     * Offline is 0 because nothing is known to be working. Maintenance stays
     * 100: it is a deliberate state, not a fault, and the state badge beside
     * the score already says so.
     */
    public function getHealthScoreAttribute(): int
    {
        $status = $this->status;
        if ($status === 'offline') {
            return 0;
        }
        if ($status === 'pending') {
            return 50;      // enrolled but never heard from
        }
        if ($status === 'maintenance') {
            return 100;
        }

        $score = 100;
        if ($this->docker_error) {
            $score -= 35;
        }
        // Each utilisation band costs progressively more, so one saturated
        // resource is enough to push a server down the list.
        foreach ([[$this->cpu_pct, 90, 75], [$this->ram_pct, 90, 75], [$this->disk_pct, 85, 70]] as [$value, $high, $warn]) {
            $value = (float) ($value ?? 0);
            if ($value > $high) {
                $score -= 20;
            } elseif ($value > $warn) {
                $score -= 10;
            }
        }
        if ($this->last_heartbeat_at && $this->last_heartbeat_at->lt(now()->subSeconds((int) (config('platform.offline_after_seconds') / 2)))) {
            $score -= 10;   // heartbeat is late but not yet offline
        }

        return max(0, min(100, $score));
    }

    /** Why the score is not 100, in the words the "Needs attention" list shows. */
    public function healthReasons(): array
    {
        $out = [];
        if ($this->status === 'offline') {
            $out[] = 'heartbeat timeout';
        }
        if ($this->status === 'pending') {
            $out[] = 'no heartbeat yet';
        }
        if ($this->docker_error) {
            $out[] = 'docker error';
        }
        foreach ([['cpu', $this->cpu_pct, 75], ['RAM', $this->ram_pct, 75], ['disk', $this->disk_pct, 70]] as [$label, $value, $warn]) {
            if ((float) ($value ?? 0) > $warn) {
                $out[] = $label.' '.round((float) $value).'%';
            }
        }

        return $out;
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
