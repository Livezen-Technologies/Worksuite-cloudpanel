<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EmailTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Password reset and email verification (the password_reset and verify_email templates).
 *
 * Reset: a random token, stored hashed in password_reset_tokens, valid for an hour and
 * once; using it signs every session out. The forgot endpoint answers the same whether
 * or not the address has an account, so it cannot be used to find accounts.
 * Verification: a signed link (user, address and expiry under an HMAC with APP_KEY), so
 * nothing is stored and changing the address invalidates old links.
 */
class AccountRecoveryController extends Controller
{
    public const RESET_MINUTES = 60;

    public const VERIFY_HOURS = 48;

    public function __construct(private AuditLogger $audit, private EmailTemplateService $emails) {}

    private static function panel(string $path, array $query): string
    {
        return rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/').$path.'?'.http_build_query($query);
    }

    public function forgot(Request $request)
    {
        app(\App\Services\Auth\CaptchaService::class)->check($request->input('captcha'), 'forgot');
        $data = $request->validate(['email' => 'required|email|max:190']);
        $email = strtolower($data['email']);
        $user = User::where('email', $email)->first();
        if ($user) {
            $this->sendReset($user);
            $this->audit->log('auth.password_reset_requested', 'users', $user->id, [], $user->current_organization_id, $user->id, $user->email);
        }

        return $this->data(['sent' => true, 'message' => 'If an account uses that address, a reset link is on its way.']);
    }

    /** Emails a one-hour reset link (also sent by an administrator from Users & Teams). */
    public function sendReset(User $user): void
    {
        $email = strtolower($user->email);
        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => Hash::make($token), 'created_at' => now()]);
        $this->emails->notify('password_reset', $user, [
            'reset_url' => self::panel('/reset-password', ['email' => $email, 'token' => $token]),
            'expires_minutes' => (string) self::RESET_MINUTES,
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:190', 'token' => 'required|string|size:64',
            'password' => ['required', 'string', Password::min(8)],
        ]);
        $email = strtolower($data['email']);
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        $user = User::where('email', $email)->first();
        if (! $row || ! $user || ! Hash::check($data['token'], $row->token) || now()->subMinutes(self::RESET_MINUTES)->gt($row->created_at)) {
            throw ApiException::validation(['token' => ['This reset link is not valid or has expired. Ask for a new one.']]);
        }
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        // Reaching the inbox proves the address too.
        $user->forceFill(['password' => $data['password'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $user->tokens()->delete();
        $this->audit->log('auth.password_reset', 'users', $user->id, [], $user->current_organization_id, $user->id, $user->email);

        return $this->data(['reset' => true]);
    }

    public static function verifyToken(User $user, int $expires): string
    {
        return $expires.'.'.hash_hmac('sha256', $user->id.'|'.strtolower($user->email).'|'.$expires, (string) config('app.key'));
    }

    /** Sends the verify_email template; called on sign-up and from "Resend". */
    public function sendVerification(User $user): bool
    {
        if ($user->email_verified_at) {
            return false;
        }

        return $this->emails->notify('verify_email', $user, [
            'verify_url' => self::panel('/verify-email', ['id' => $user->id, 'token' => self::verifyToken($user, now()->addHours(self::VERIFY_HOURS)->getTimestamp())]),
        ]);
    }

    public function resend(Request $request)
    {
        $user = $request->user();
        if ($user->email_verified_at) {
            return $this->data(['verified' => true]);
        }
        $this->sendVerification($user);

        return $this->data(['sent' => true]);
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['id' => 'required|string|max:64', 'token' => 'required|string|max:200']);
        $user = User::find($data['id']);
        [$expires] = explode('.', $data['token'], 2) + [0 => ''];
        if (! $user || ! ctype_digit($expires) || (int) $expires < now()->getTimestamp()
            || ! hash_equals(self::verifyToken($user, (int) $expires), $data['token'])) {
            throw ApiException::validation(['token' => ['This confirmation link is not valid or has expired. Sign in and send a new one.']]);
        }
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->audit->log('auth.email_verified', 'users', $user->id, [], $user->current_organization_id, $user->id, $user->email);
        }

        return $this->data(['verified' => true, 'email' => $user->email]);
    }
}
