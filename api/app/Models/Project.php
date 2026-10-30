<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** ENV-1: a project groups the environments (production, staging, …) of one product. */
class Project extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'projects';

    protected $guarded = ['id'];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return ['webhook_secret' => 'encrypted'];
    }

    public function environments() { return $this->hasMany(Environment::class); }
}
