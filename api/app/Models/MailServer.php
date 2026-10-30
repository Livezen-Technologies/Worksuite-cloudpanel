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

    protected $hidden = ['relay_password', 'master_password'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'settings' => 'array', 'relay_password' => 'encrypted', 'master_password' => 'encrypted', 'health' => 'array', 'tls' => 'array', 'health_checked_at' => 'datetime'];
    }
}
