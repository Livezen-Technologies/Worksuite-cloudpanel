<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** Mail hosting (MAIL). */
class MailServer extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['dkim_private', 'password_hash'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }
}
