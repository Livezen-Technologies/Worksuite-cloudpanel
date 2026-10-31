<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** SSO-ID: a person's use of one sign-in app (Google, GitHub, Microsoft, OIDC). */
class SsoIdentity extends Model
{
    use Uuid;

    protected $table = 'sso_identities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'blocked_at' => 'datetime'];
    }
}
