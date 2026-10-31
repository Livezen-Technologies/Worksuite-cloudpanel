<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationService;
use App\Support\Rbac;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function register(Request $request, OrganizationService $organizations)
    {
        app(\App\Services\Auth\CaptchaService::class)->check($request->input('captcha'), 'signup');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => ['required', 'string', Password::min(8)],
            'organization' => 'required|string|max:120',
        ]);
        [$user, $org] = DB::transaction(function () use ($data, $organizations) {
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $org = $organizations->create($data['organization'], $user);

            return [$user->fresh(), $org];
        });
        $this->audit->log('auth.register', 'users', $user->id, [], $org->id, $user->id, $user->email);
        app(\App\Services\EmailTemplateService::class)->notify('welcome', $user);
        app(AccountRecoveryController::class)->sendVerification($user);

        return $this->data(['token' => $this->issue($user), 'user' => $user], 201);
    }

    public function login(Request $request)
    {
        // SVC-6: the bot check comes first (not on the second step, when only the code is new).
        if (! $request->filled('otp') && ! $request->filled('passkey')) {
            app(\App\Services\Auth\CaptchaService::class)->check($request->input('captcha'), 'login');
        }
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'otp' => 'nullable|string',
            'passkey' => 'nullable|array', 'passkey.challenge_id' => 'required_with:passkey|string', 'passkey.credential' => 'required_with:passkey|array']);
        \App\Support\SignInLock::guard($data['email']);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            \App\Support\SignInLock::fail($data['email'], $user);
            throw new ApiException('unauthenticated', 'The email or password is incorrect.', 401);
        }
        $this->guardRequiredSso($user);
        // Second factor: TOTP code or a passkey assertion for this user (UACC-002).
        if (! empty($data['passkey'])) {
            app(\App\Services\WebAuthnService::class)->authenticate($data['passkey']['challenge_id'], $data['passkey']['credential'], $user);
        } elseif ($user->mfa_enabled && ! Totp::verify((string) $user->mfa_secret, $data['otp'] ?? null)) {
            // MFA-REC: a one-time recovery code stands in for a lost authenticator.
            if (! \App\Support\RecoveryCodes::consume($user, $data['otp'] ?? null)) {
                if (! empty($data['otp'])) {
                    \App\Support\SignInLock::fail($user->email, $user);
                }
                throw new ApiException('unauthenticated', empty($data['otp']) ? 'Enter the code from your authenticator app, a recovery code, or use a passkey.' : 'The two-factor code is incorrect.', 401, ['otp' => ['A valid one-time code is required.']],
                    ['mfa_required' => true, 'passkey_available' => \App\Models\Passkey::where('user_id', $user->id)->exists()]);
            }

            return $this->completeLogin($user, 'password+recovery_code');
        }

        return $this->completeLogin($user, ! empty($data['passkey']) ? 'password+passkey' : ($user->mfa_enabled ? 'password+totp' : 'password'));
    }

    /**
     * SSO-POL: an organisation can require its members to sign in with a sign-in app. Owners and super admins
     * keep their password so a broken provider never locks everyone out; the rule waits until an app is set up.
     */
    private function guardRequiredSso(User $user): void
    {
        $org = $user->currentOrganization ?? $user->memberships()->where('status', 'active')->first()?->organization;
        if (! $org?->setting('security.require_sso') || in_array($user->roleName(), ['owner', 'super_admin'], true)) {
            return;
        }
        $apps = app(\App\Services\Auth\SsoService::class)->enabled();
        if (! $apps) {
            return;
        }
        $names = implode(' or ', array_column($apps, 'label'));
        throw new ApiException('sso_required', "Your organisation signs in with {$names}. Use that button below instead of your password.", 403, [], ['sso_required' => true, 'sso' => $apps]);
    }

    /** SVC-6/7: what the sign-in page offers (captcha, sign-in apps). Public. */
    public function config()
    {
        return $this->data(['captcha' => app(\App\Services\Auth\CaptchaService::class)->publicConfig(), 'sso' => app(\App\Services\Auth\SsoService::class)->enabled()]);
    }

    /** SVC-7: the provider's authorisation URL. */
    public function ssoStart(string $provider)
    {
        return $this->data(['url' => app(\App\Services\Auth\SsoService::class)->start($provider)]);
    }

    /**
     * SVC-7: finishes a provider sign-in. Accounts with two-factor still need their code:
     * the first call returns mfa_required with a one-time ticket, the second sends ticket + otp.
     */
    public function ssoFinish(Request $request)
    {
        $data = $request->validate(['state' => 'required_without:ticket|string|max:100', 'code' => 'required_without:ticket|string|max:2000', 'ticket' => 'nullable|string|max:80', 'otp' => 'nullable|string|max:20']);
        if (! empty($data['ticket'])) {
            $user = User::find(\Illuminate\Support\Facades\Cache::get('sso:ticket:'.$data['ticket']));
            if (! $user) {
                throw ApiException::validation(['ticket' => ['This sign-in has expired. Start again.']]);
            }
        } else {
            $user = app(\App\Services\Auth\SsoService::class)->finish($data['state'], $data['code']);
        }
        \App\Support\SignInLock::guard($user->email);
        $viaRecovery = false;
        if ($user->mfa_enabled && ! Totp::verify((string) $user->mfa_secret, $data['otp'] ?? null)
            && ! ($viaRecovery = \App\Support\RecoveryCodes::consume($user, $data['otp'] ?? null))) {
            if (! empty($data['otp'])) {
                \App\Support\SignInLock::fail($user->email, $user);
            }
            $ticket = $data['ticket'] ?? \Illuminate\Support\Str::random(40);
            \Illuminate\Support\Facades\Cache::put('sso:ticket:'.$ticket, $user->id, now()->addMinutes(5));
            throw new ApiException('unauthenticated', empty($data['otp']) ? 'Enter the code from your authenticator app.' : 'The two-factor code is incorrect.', 401, ['otp' => ['A valid one-time code is required.']], ['mfa_required' => true, 'ticket' => $ticket]);
        }
        if (! empty($data['ticket'])) {
            \Illuminate\Support\Facades\Cache::forget('sso:ticket:'.$data['ticket']);
        }

        return $this->completeLogin($user, $viaRecovery ? 'sso+recovery_code' : 'sso');
    }

    /** Issues the session token after a successful sign-in (password(+2FA) or passkey). */
    public function completeLogin(User $user, string $method)
    {
        \App\Support\SignInLock::clear($user->email);
        if (! $user->current_organization_id || ! $user->membership()) {
            $user->current_organization_id = $user->memberships()->where('status', 'active')->value('organization_id');
        }
        $user->forceFill(['last_login_at' => now()])->save();
        // EML: a sign-in from an address this user has not signed in from before.
        $ip = request()->ip();
        $seen = \App\Models\AuditLog::where('user_id', $user->id)->where('action', 'auth.login');
        if ($ip && (clone $seen)->exists() && ! (clone $seen)->where('ip', $ip)->exists()) {
            app(\App\Services\EmailTemplateService::class)->notify('new_sign_in', $user, [
                'ip' => $ip, 'location' => 'Unknown', 'device' => \Illuminate\Support\Str::limit((string) request()->userAgent(), 120) ?: 'Unknown', 'time' => now()->toDayDateTimeString().' UTC',
            ]);
        }
        $this->audit->log('auth.login', 'users', $user->id, ['method' => $method], $user->current_organization_id, $user->id, $user->email);

        return $this->data(['token' => $this->issue($user), 'user' => $user]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request, Rbac $rbac)
    {
        $user = $request->user();
        $role = $user->roleName();

        return $this->data([
            'user' => $user->toArray() + ['mfa_recovery_codes_left' => $user->mfa_enabled ? \App\Support\RecoveryCodes::remaining($user) : null],
            'organization' => $user->currentOrganization,
            'role' => $role,
            'permissions' => $rbac->permissionsFor($role),
            'mfa_setup_required' => (bool) ($user->currentOrganization?->setting('security.require_mfa')) && ! $user->hasSecondFactor(),
            'organizations' => $user->memberships()->with('organization:id,name,slug')->get()->map(fn ($m) => ['id' => $m->organization_id, 'name' => $m->organization?->name, 'role' => $m->role]),
        ]);
    }

    public function mfaEnable(Request $request)
    {
        $user = $request->user();
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => false])->save();
        $issuer = rawurlencode(config('app.name'));

        return $this->data(['secret' => $secret, 'otpauth_url' => "otpauth://totp/{$issuer}:".rawurlencode($user->email)."?secret={$secret}&issuer={$issuer}&digits=6&period=30"]);
    }

    public function mfaConfirm(Request $request)
    {
        $data = $request->validate(['otp' => 'required|string']);
        $user = $request->user();
        if (! $user->mfa_secret || ! Totp::verify($user->mfa_secret, $data['otp'])) {
            throw ApiException::validation(['otp' => ['The code is incorrect. Check the time on your device and try again.']]);
        }
        $user->forceFill(['mfa_enabled' => true])->save();

        // Shown once: the person saves them somewhere safe.
        return $this->data(['mfa_enabled' => true, 'recovery_codes' => \App\Support\RecoveryCodes::generate($user)]);
    }

    /** SSO-ID: the sign-in apps this person can use, with last use and whether they turned one off. */
    public function signInApps(Request $request)
    {
        return $this->data(app(\App\Services\Auth\SsoService::class)->forUser($request->user()));
    }

    public function updateSignInApp(Request $request, string $provider)
    {
        $data = $request->validate(['blocked' => 'required|boolean']);
        $user = $request->user();
        app(\App\Services\Auth\SsoService::class)->setBlocked($user, $provider, $data['blocked']);
        $this->audit->log($data['blocked'] ? 'auth.sign_in_app_blocked' : 'auth.sign_in_app_allowed', 'users', $user->id, ['provider' => $provider], $user->current_organization_id, $user->id, $user->email);

        return $this->data(app(\App\Services\Auth\SsoService::class)->forUser($user));
    }

    /** MFA-REC: a fresh set of recovery codes; the old ones stop working. Needs a current code. */
    public function mfaRecoveryCodes(Request $request)
    {
        $data = $request->validate(['otp' => 'required|string']);
        $user = $request->user();
        if (! $user->mfa_enabled || ! Totp::verify((string) $user->mfa_secret, $data['otp'])) {
            throw ApiException::validation(['otp' => ['The code is incorrect.']]);
        }
        $this->audit->log('auth.recovery_codes_regenerated', 'users', $user->id, [], $user->current_organization_id, $user->id, $user->email);

        return $this->data(['recovery_codes' => \App\Support\RecoveryCodes::generate($user)]);
    }

    public function mfaDisable(Request $request)
    {
        $data = $request->validate(['otp' => 'required|string']);
        $user = $request->user();
        if (! $user->mfa_enabled || ! Totp::verify((string) $user->mfa_secret, $data['otp'])) {
            throw ApiException::validation(['otp' => ['The code is incorrect.']]);
        }
        $user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null, 'mfa_recovery_codes' => null])->save();
        app(\App\Services\EmailTemplateService::class)->notify('two_factor_disabled', $user, ['time' => now()->toDayDateTimeString().' UTC']);

        return $this->data(['mfa_enabled' => false]);
    }

    private function issue(User $user): string
    {
        $token = $user->createToken('session', ['*'], now()->addDays(30));
        $token->accessToken->forceFill(['organization_id' => $user->current_organization_id])->save();

        return $token->plainTextToken;
    }
}
