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

    /**
     * Sanctum looks a "id|secret" token up by its id. Ids here are UUIDs, so an id that is
     * not one (a forged or truncated token) is simply no token, not a database error and
     * a 500 that anyone could trigger.
     */
    public static function findToken($token)
    {
        if (str_contains($token, '|')) {
            [$id] = explode('|', $token, 2);
            if (! \Illuminate\Support\Str::isUuid($id)) {
                return null;
            }
        }

        return parent::findToken($token);
    }
}
