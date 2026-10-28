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

        return $this->data(['token' => $this->issue($user), 'user' => $user], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'otp' => 'nullable|string',
            'passkey' => 'nullable|array', 'passkey.challenge_id' => 'required_with:passkey|string', 'passkey.credential' => 'required_with:passkey|array']);
        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException('unauthenticated', 'The email or password is incorrect.', 401);
        }
        // Second factor: TOTP code or a passkey assertion for this user (UACC-002).
        if (! empty($data['passkey'])) {
            app(\App\Services\WebAuthnService::class)->authenticate($data['passkey']['challenge_id'], $data['passkey']['credential'], $user);
        } elseif ($user->mfa_enabled && ! Totp::verify((string) $user->mfa_secret, $data['otp'] ?? null)) {
            throw new ApiException('unauthenticated', empty($data['otp']) ? 'Enter the code from your authenticator app or use a passkey.' : 'The two-factor code is incorrect.', 401, ['otp' => ['A valid one-time code is required.']],
                ['mfa_required' => true, 'passkey_available' => \App\Models\Passkey::where('user_id', $user->id)->exists()]);
        }

        return $this->completeLogin($user, ! empty($data['passkey']) ? 'password+passkey' : ($user->mfa_enabled ? 'password+totp' : 'password'));
    }

    /** Issues the session token after a successful sign-in (password(+2FA) or passkey). */
    public function completeLogin(User $user, string $method)
    {
        if (! $user->current_organization_id || ! $user->membership()) {
            $user->current_organization_id = $user->memberships()->where('status', 'active')->value('organization_id');
        }
        $user->forceFill(['last_login_at' => now()])->save();
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
            'user' => $user,
            'organization' => $user->currentOrganization,
            'role' => $role,
            'permissions' => $rbac->permissionsFor($role),
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

        return $this->data(['mfa_enabled' => true]);
    }

    public function mfaDisable(Request $request)
    {
        $data = $request->validate(['otp' => 'required|string']);
        $user = $request->user();
        if (! $user->mfa_enabled || ! Totp::verify((string) $user->mfa_secret, $data['otp'])) {
            throw ApiException::validation(['otp' => ['The code is incorrect.']]);
        }
        $user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null])->save();

        return $this->data(['mfa_enabled' => false]);
    }

    private function issue(User $user): string
    {
        $token = $user->createToken('session', ['*'], now()->addDays(30));
        $token->accessToken->forceFill(['organization_id' => $user->current_organization_id])->save();

        return $token->plainTextToken;
    }
}
