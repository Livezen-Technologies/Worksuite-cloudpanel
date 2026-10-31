<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AUTH-LOCK: failed sign-ins counted per account, whatever address they come from, so a
 * password or 6-digit code cannot be guessed by spreading attempts over many IPs.
 */
class SignInLock
{
    public const MAX_FAILURES = 10;
    public const WINDOW_SECONDS = 900;

    private static function key(string $email): string
    {
        return 'signin-fail:'.sha1(strtolower(trim($email)));
    }

    /** Refuses the attempt while the account is locked. */
    public static function guard(string $email): void
    {
        if (RateLimiter::tooManyAttempts(self::key($email), self::MAX_FAILURES)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn(self::key($email)) / 60));
            throw new ApiException('too_many_attempts', "Too many failed sign-ins for this account. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').', or reset your password.', 429, [], ['locked_for_minutes' => $minutes]);
        }
    }

    /** Counts a wrong password or code; tells the owner once when the account locks. */
    public static function fail(string $email, ?User $user = null): void
    {
        $key = self::key($email);
        RateLimiter::hit($key, self::WINDOW_SECONDS);
        if ($user && RateLimiter::attempts($key) === self::MAX_FAILURES) {
            app(\App\Services\EmailTemplateService::class)->notify('sign_in_locked', $user, [
                'attempts' => (string) self::MAX_FAILURES, 'minutes' => (string) (self::WINDOW_SECONDS / 60),
                'ip' => (string) request()->ip(), 'time' => now()->toDayDateTimeString().' UTC',
            ]);
        }
    }

    public static function clear(string $email): void
    {
        RateLimiter::clear(self::key($email));
    }
}
