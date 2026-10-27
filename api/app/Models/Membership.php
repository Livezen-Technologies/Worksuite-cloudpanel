<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'memberships';

    protected $guarded = ['id'];

    protected $hidden = ['invite_token_hash'];

    protected function casts(): array
    {
        return [

        ];
    }

    public function user() { return $this->belongsTo(User::class); }
}
