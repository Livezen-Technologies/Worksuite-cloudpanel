<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'networks';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'internal' => 'boolean',
            'ingress_attached_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }

    public function project() { return $this->belongsTo(Project::class)->withoutGlobalScope('organization'); }

    /** The apps in the environment this network serves (environment networks only). */
    public function applications() { return $this->hasMany(Application::class, 'environment_id', 'environment_id')->withoutGlobalScope('organization'); }
}
