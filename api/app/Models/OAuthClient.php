<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** UAI: MCP OAuth. Not tenant-scoped: looked up by client id or token hash before anyone is signed in. */
class OAuthClient extends Model
{
    use Uuid;

    protected $table = 'oauth_clients';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }
}
