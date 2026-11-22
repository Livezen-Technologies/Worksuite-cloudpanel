<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    use BelongsToOrganization, SoftDeletes, Uuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => 'encrypted:array', // may hold registry credentials
            'requires_approval' => 'boolean',
            'auto_deploy' => 'boolean',
            'auto_restart' => 'boolean',
            'last_deployed_at' => 'datetime',
            'asleep_at' => 'datetime',
            'woken_at' => 'datetime',
            'purge_after' => 'datetime',
            'scaling' => 'array',
            'replicas' => 'integer',
            'public_access' => 'boolean',
            'locked_at' => 'datetime',
            'maintenance_until' => 'datetime',
            'maintenance_scheduled_start' => 'datetime',
            'maintenance_scheduled_end' => 'datetime',
            'maintenance_auto_locked' => 'boolean',
        ];
    }

    /**
     * XNODE-1: an app whose home server is not the ingress is reached by the ingress's
     * Traefik over the private network, at its fixed dmp-mesh address. Turn that on when
     * the app is created on, or moved to, such a server while a dedicated ingress exists.
     * Apps on the ingress itself are left alone (no new network).
     */
    protected static function booted(): void
    {
        static::saving(function (Application $app) {
            if ($app->mesh_network || ! $app->server_id || ! $app->isDirty('server_id')) {
                return;
            }
            if (self::needsMesh(Server::allTenants()->find($app->server_id))) {
                $app->mesh_network = true;
            }
        });
    }

    /** True when $server is not an ingress but a dedicated ingress server exists. */
    public static function needsMesh(?Server $server): bool
    {
        if (! $server || in_array('ingress', (array) ($server->tags ?? []), true) || \App\Services\TraefikConfigService::isEdge($server)) {
            return false;
        }
        $ids = (array) config('platform.traefik.ingress_server_ids');
        if ($ids) {
            return ! in_array($server->id, $ids, true);
        }

        return Server::allTenants()->get(['id', 'tags'])->contains(fn ($s) => in_array('ingress', (array) ($s->tags ?? []), true) || \App\Services\TraefikConfigService::isEdge($s));
    }

    protected $hidden = ['source'];

    protected $appends = ['source_summary'];

    public function server() { return $this->belongsTo(Server::class)->withoutGlobalScope('organization'); }
    public function envVars() { return $this->hasMany(EnvVar::class); }
    public function environmentModel() { return $this->belongsTo(Environment::class, 'environment_id'); }
    public function releases() { return $this->hasMany(Release::class)->orderByDesc('version'); }
    public function deployments() { return $this->hasMany(Deployment::class)->latest(); }
    public function domains() { return $this->hasMany(Domain::class); }
    public function containers() { return $this->hasMany(Container::class); }
    public function currentRelease() { return $this->belongsTo(Release::class, 'current_release_id'); }
    public function members() { return $this->belongsToMany(User::class, 'application_members'); }

    /** Source without credentials. */
    public function getSourceSummaryAttribute(): array
    {
        $source = $this->source ?? [];
        unset($source['registry_auth']);

        return $source;
    }

    public function containerName(): string
    {
        return 'app-'.$this->name;
    }

    /**
     * Soft-delete the app (data kept for the retention window) and stop its containers, keeping volumes.
     * Shared by the admin and customer delete paths and the approved `application.delete`.
     */
    public function retire(): void
    {
        $this->update(['status' => 'deleted', 'purge_after' => now()->addDays(config('platform.deleted_app_retention_days'))]);
        // CRON-7: an app's scheduled jobs go with it.
        $jobs = ScheduledJob::allTenants()->where('application_id', $this->id)->pluck('id');
        ScheduledJobRun::allTenants()->whereIn('scheduled_job_id', $jobs)->delete();
        ScheduledJob::allTenants()->whereIn('id', $jobs)->delete();
        foreach (Container::where('application_id', $this->id)->get() as $container) {
            app(\App\Services\ContainerService::class)->perform($container, 'remove', ['keep_volumes' => true]);
        }
        $this->delete();
    }
}
