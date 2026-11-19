<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** PDNS: the DNS permissions a project owner gave one member on one project. */
class ProjectDomainGrant extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
