<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** CUS-3: support sessions are customer tokens named "impersonation:{staff id}". */
class Impersonation
{
    public const PREFIX = 'impersonation:';

    /** Account routes a support session may not use: sign-in settings stay the owner's. */
    public const BLOCKED = ['api/v1/me/password', 'api/v1/me/profile', 'api/v1/me/mfa/*', 'api/v1/me/passkeys*', 'api/v1/me/sign-in-apps*', 'api/v1/me/sessions*', 'api/v1/me/verify-email'];

    public static function staffFor(?User $user): ?User
    {
        $name = (string) $user?->currentAccessToken()?->name;

        return str_starts_with($name, self::PREFIX) ? User::find(substr($name, strlen(self::PREFIX))) : null;
    }

    public static function blocks(Request $request): bool
    {
        return ! $request->isMethod('GET') && $request->is(...self::BLOCKED);
    }
}
