<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** PWS: a user's role on one project. */
class ProjectMember extends Model
{
    use Uuid;

    public const ROLES = ['owner', 'developer', 'viewer'];

    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }
}
