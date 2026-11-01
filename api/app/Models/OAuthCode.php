<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** UAI: MCP OAuth. Not tenant-scoped: looked up by client id or token hash before anyone is signed in. */
class OAuthCode extends Model
{
    use Uuid;

    protected $table = 'oauth_codes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['write' => 'boolean', 'expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
