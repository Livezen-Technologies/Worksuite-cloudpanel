<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** Mail hosting (MAIL). */
class MailDomain extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['dkim_private', 'password_hash'];

    public function server() { return $this->belongsTo(MailServer::class, 'mail_server_id'); }

    protected function casts(): array
    {
        return ['dkim_private' => 'encrypted', 'dns' => 'array', 'dns_checked_at' => 'datetime'];
    }
}
