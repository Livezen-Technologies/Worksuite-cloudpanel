<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** SSH-GW: one of a person's SSH public keys. Belongs to the person, not an organisation. */
class UserSshKey extends Model
{
    use Uuid;

    protected $table = 'user_ssh_keys';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
