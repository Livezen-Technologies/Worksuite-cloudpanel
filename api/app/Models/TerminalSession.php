<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class TerminalSession extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'terminal_sessions';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function container() { return $this->belongsTo(Container::class); }
}
