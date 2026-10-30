<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** ENV-1..7: one environment of a project, with its apps, shared variables, branch and sleep policy. */
class Environment extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'environments';

    protected $guarded = ['id'];

    protected $hidden = ['variables'];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'variables' => 'encrypted:array',
            'sleep_after_minutes' => 'integer',
        ];
    }

    public function project() { return $this->belongsTo(Project::class); }
    public function applications() { return $this->hasMany(Application::class); }
}
