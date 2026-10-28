<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Laravel\Sanctum\PersonalAccessToken;

/** Sanctum tokens stored in the SRS `access_tokens` table. */
class AccessToken extends PersonalAccessToken
{
    use Uuid;

    protected $table = 'access_tokens';

    protected $hidden = ['token'];
}
