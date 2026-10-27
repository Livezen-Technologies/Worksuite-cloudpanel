<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ComposeProject extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'compose_projects';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [

        ];
    }

    public function revisions() { return $this->hasMany(ComposeRevision::class)->orderByDesc('number'); }
    public function services() { return $this->hasMany(ComposeService::class); }
    public function server() { return $this->belongsTo(Server::class); }
    public function currentRevision() { return $this->belongsTo(ComposeRevision::class, 'current_revision_id'); }
    public function runningRevision() { return $this->belongsTo(ComposeRevision::class, 'running_revision_id'); }
}
