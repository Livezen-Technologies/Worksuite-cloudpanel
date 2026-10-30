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
            'purge_after' => 'datetime',
        ];
    }

    protected $hidden = ['source'];

    protected $appends = ['source_summary'];

    public function server() { return $this->belongsTo(Server::class)->withoutGlobalScope('organization'); }
    public function envVars() { return $this->hasMany(EnvVar::class); }
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
