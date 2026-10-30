<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** One IMAP import into a mailbox (MCL-10). */
class MailImport extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['source_password'];

    protected function casts(): array
    {
        return ['source_password' => 'encrypted', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function mailbox()
    {
        return $this->belongsTo(Mailbox::class);
    }
}
