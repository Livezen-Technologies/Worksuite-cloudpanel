<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\Membership;
use App\Models\User;
use App\Services\ProjectAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Staff SSO: "Sign in with WorkSuite Cloud" for panel staff (super_admin, infra_admin, devops).
 * OneAuth's staff audience vouches for a WorkSuite Cloud admin and, since part 2, for their panel role
 * (`wsc_app_role`, granted per app in Cloud Admin). The panel finds the user by `wsc_sub`, else links an
 * existing user by email, else creates one, and gives them that role in the staff organisation.
 * Cloud Admin also pushes signed provisioning events (upsert / disable) to POST /api/v1/auth/wsc/provision.
 * Authorisation code + PKCE, state and nonce are single-use, and every token is checked against the
 * issuer's JWKS (signature, iss, aud, exp; nonce for sign-in, a single-use jti for provisioning).
 * Configured from env only (services.wsc_oidc); while unset the option is not offered.
 */
class WscStaffSso
{
    public const KEY = 'wsc';

    public const LABEL = 'WorkSuite Cloud';

    public function configured(): bool
    {
        $c = config('services.wsc_oidc', []);

        return ! empty($c['issuer']) && ! empty($c['client_id']) && ! empty($c['client_secret']);
    }

    public function redirectUri(): string
    {
        return (string) (config('services.wsc_oidc.redirect_uri')
            ?: rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/').'/auth/wsc/callback');
    }

    private function issuer(): string
    {
        return rtrim((string) config('services.wsc_oidc.issuer'), '/');
    }

    private function discovery(): array
    {
        $iss = $this->issuer();

        return Cache::remember('wsc_oidc:disco:'.md5($iss), 3600, fn () => Http::timeout(8)->acceptJson()->get($iss.'/.well-known/openid-configuration')->throw()->json());
    }

    private function guard(): void
    {
        if (! $this->configured()) {
            throw new ApiException('not_found', 'That sign-in option is not set up.', 404);
        }
    }

    /** The authorisation URL; `silent` asks for prompt=none (the launcher's ?sso=1). */
    public function start(bool $silent = false): string
    {
        $this->guard();
        $d = $this->discovery();
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        Cache::put("sso:state:{$state}", ['key' => self::KEY, 'verifier' => $verifier, 'nonce' => $nonce], now()->addMinutes(10));

        return $d['authorization_endpoint'].'?'.http_build_query(array_filter([
            'client_id' => config('services.wsc_oidc.client_id'), 'redirect_uri' => $this->redirectUri(), 'response_type' => 'code',
            'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'prompt' => $silent ? 'none' : null,
        ]));
    }

    /** Exchanges the code for tokens, verifies the id_token and returns the matching staff user. */
    public function finish(array $st, string $code): User
    {
        $this->guard();
        $d = $this->discovery();
        $tok = Http::asForm()->acceptJson()->timeout(10)->post($d['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri(),
            'client_id' => config('services.wsc_oidc.client_id'), 'client_secret' => config('services.wsc_oidc.client_secret'), 'code_verifier' => $st['verifier'],
        ])->json();
        $idToken = is_array($tok) ? ($tok['id_token'] ?? null) : null;
        if (! is_string($idToken) || $idToken === '') {
            throw new ApiException('unauthenticated', 'WorkSuite Cloud did not accept the sign-in.', 401);
        }
        $claims = $this->verify($idToken, (string) $d['jwks_uri'], (string) ($st['nonce'] ?? ''));
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if ($email === '' || ! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) || ($claims['wsc_staff'] ?? false) !== true) {
            throw new ApiException('unauthenticated', 'WorkSuite Cloud did not vouch for a verified staff email address.', 401);
        }
        $role = (string) ($claims['wsc_app_role'] ?? '');
        if (! in_array($role, ProjectAccess::PLATFORM_ROLES, true)) {
            throw new ApiException('forbidden', "You don't have a CloudPanel role in WorkSuite Cloud ({$email}). Ask a WorkSuite Cloud administrator for access.", 403);
        }
        $orgId = $this->staffOrganizationId();
        $user = $this->upsert((string) ($claims['sub'] ?? ''), $email, (string) ($claims['name'] ?? ''), $role);
        $active = Membership::allTenants()->where('organization_id', $orgId)->where('user_id', $user->id)->where('status', 'active')->exists();
        if (! $active) {
            throw new ApiException('forbidden', "The CloudPanel account for {$email} is suspended.", 403);
        }

        return $user;
    }

    /** The organisation panel staff are members of: WSC_STAFF_ORGANIZATION_ID, else the oldest super admin's (else any staff member's). */
    public function staffOrganizationId(): string
    {
        $id = config('services.wsc_oidc.staff_organization_id')
            ?: Membership::allTenants()->where('role', 'super_admin')->where('status', 'active')->orderBy('created_at')->orderBy('id')->value('organization_id')
            ?: Membership::allTenants()->whereIn('role', ProjectAccess::PLATFORM_ROLES)->orderBy('created_at')->orderBy('id')->value('organization_id');
        if (! $id) {
            throw new ApiException('conflict', 'CloudPanel has no staff organisation yet (no super admin). Set WSC_STAFF_ORGANIZATION_ID.', 409);
        }

        return (string) $id;
    }

    /**
     * Links (by `wsc_sub`, else by email, case-insensitive) or creates the WorkSuite Cloud admin's panel user and
     * gives them `$role` in the staff organisation. Re-enables them only when WorkSuite Cloud disabled them.
     */
    public function upsert(string $sub, string $email, string $name, string $role): User
    {
        $email = strtolower(trim($email));
        if (! preg_match('/^staff:[A-Za-z0-9_-]{1,64}$/', $sub) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ProjectAccess::PLATFORM_ROLES, true)) {
            throw new ApiException('unauthenticated', 'WorkSuite Cloud sent an incomplete staff account.', 401);
        }
        $orgId = $this->staffOrganizationId();

        return DB::transaction(function () use ($sub, $email, $name, $role, $orgId) {
            $user = User::where('wsc_sub', $sub)->lockForUpdate()->first();
            if (! $user) {
                $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                if ($user && $user->wsc_sub && $user->wsc_sub !== $sub) {
                    throw new ApiException('conflict', "{$email} is already linked to another WorkSuite Cloud admin.", 409);
                }
            }
            $name = trim($name) !== '' ? mb_substr(trim($name), 0, 120) : Str::before($email, '@');
            if (! $user) {
                $user = User::create(['name' => $name, 'email' => $email, 'password' => Str::random(64), 'email_verified_at' => now(), 'current_organization_id' => $orgId, 'sign_in_method' => 'sso']);
            } elseif ($user->email !== $email && ! User::whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $user->id)->exists()) {
                $user->email = $email;
            }
            $user->forceFill(['wsc_sub' => $sub, 'managed_by_wsc' => true, 'name' => $name, 'current_organization_id' => $user->current_organization_id ?: $orgId]);
            $m = Membership::allTenants()->where('organization_id', $orgId)->where('user_id', $user->id)->first()
                ?? new Membership(['organization_id' => $orgId, 'user_id' => $user->id, 'status' => 'active']);
            $m->role = $role;
            $m->invite_token_hash = null;
            if ($user->wsc_disabled_at) {
                $m->status = 'active'; // only undo a WorkSuite Cloud disable, never a local suspension
                $user->wsc_disabled_at = null;
            }
            $user->save();
            $m->save();

            return $user;
        });
    }

    /** Disables a linked (or email-matched) user in the staff organisation and ends their sessions; their data stays. */
    public function disable(string $sub, string $email): void
    {
        $user = User::where('wsc_sub', $sub)->first()
            ?? ($email !== '' ? User::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->whereNull('wsc_sub')->first() : null);
        if (! $user) {
            return;
        }
        $orgId = $this->staffOrganizationId();
        $m = Membership::allTenants()->where('organization_id', $orgId)->where('user_id', $user->id)->first();
        if (! $m) {
            return;
        }
        DB::transaction(function () use ($user, $m, $sub, $orgId) {
            if ($m->status === 'active') {
                $m->forceFill(['status' => 'suspended'])->save();
                $user->wsc_disabled_at = now();
            }
            $user->forceFill(['wsc_sub' => $sub, 'managed_by_wsc' => true])->save();
            $user->tokens()->where('organization_id', $orgId)->delete();
        });
    }

    /** Verifies a provisioning JWS (iss, aud, exp, single-use jti) and applies it. */
    public function provision(string $jwt): void
    {
        $this->guard();
        $claims = $this->verify($jwt, (string) $this->discovery()['jwks_uri'], null);
        $jti = (string) ($claims['jti'] ?? '');
        $event = (string) ($claims['event'] ?? '');
        if ($jti === '' || strlen($jti) > 200 || ! in_array($event, ['upsert', 'disable'], true) || ! is_numeric($claims['iat'] ?? null)) {
            throw new ApiException('bad_request', 'That provisioning event is incomplete.', 400);
        }
        if (! Cache::add('wsc_prov:jti:'.hash('sha256', $jti), 1, max(60, (int) $claims['exp'] - time() + 120))) {
            throw new ApiException('unauthenticated', 'That provisioning event was already used.', 401);
        }
        $sub = (string) ($claims['sub'] ?? '');
        $event === 'upsert'
            ? $this->upsert($sub, (string) ($claims['email'] ?? ''), (string) ($claims['name'] ?? ''), (string) ($claims['role'] ?? ''))
            : $this->disable($sub, (string) ($claims['email'] ?? ''));
        app(\App\Services\AuditLogger::class)->log('users.wsc_'.$event, 'users', User::where('wsc_sub', $sub)->value('id'), ['sub' => $sub, 'role' => $claims['role'] ?? null], $this->staffOrganizationId());
    }

    /** Verifies an RS256 token against the JWKS and the expected claims (nonce when given); returns the claims. */
    public function verify(string $jwt, string $jwksUri, ?string $nonce): array
    {
        $bad = fn () => new ApiException('unauthenticated', 'The WorkSuite Cloud sign-in could not be verified.', 401);
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw $bad();
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64($h), true);
        $claims = json_decode(self::b64($p), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
            throw $bad();
        }
        $key = $this->jwk($jwksUri, (string) ($header['kid'] ?? ''));
        if (! $key || openssl_verify("{$h}.{$p}", self::b64($s), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw $bad();
        }
        $aud = (array) ($claims['aud'] ?? []);
        $now = time();
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $this->issuer()
            || ! in_array(config('services.wsc_oidc.client_id'), $aud, true)
            || ! is_numeric($claims['exp'] ?? null) || $claims['exp'] + 60 < $now
            || ($nonce !== null && ($nonce === '' || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))))) {
            throw $bad();
        }

        return $claims;
    }

    /** The RSA public key for `kid`; refetches the JWKS once when the kid is unknown (key rotation). */
    private function jwk(string $uri, string $kid): mixed
    {
        $ck = 'wsc_oidc:jwks:'.md5($uri);
        foreach ([false, true] as $fresh) {
            if ($fresh) {
                Cache::forget($ck);
            }
            $keys = Cache::remember($ck, 3600, fn () => Http::timeout(8)->acceptJson()->get($uri)->throw()->json('keys') ?? []);
            foreach ($keys as $k) {
                if (($k['kty'] ?? '') === 'RSA' && ($kid === '' || ($k['kid'] ?? '') === $kid) && ! empty($k['n']) && ! empty($k['e'])) {
                    return openssl_pkey_get_public(self::rsaPem(self::b64($k['n']), self::b64($k['e'])));
                }
            }
        }

        return null;
    }

    private static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private static function der(int $tag, string $body): string
    {
        $len = strlen($body);
        $l = $len < 128 ? chr($len) : (($b = ltrim(pack('N', $len), "\0")) ? chr(0x80 | strlen($b)).$b : '');

        return chr($tag).$l.$body;
    }

    private static function rsaPem(string $n, string $e): string
    {
        $int = fn ($v) => self::der(0x02, (ord($v[0] ?? "\0") > 0x7F ? "\0" : '').$v);
        $rsa = self::der(0x30, $int($n).$int($e));
        $spki = self::der(0x30, self::der(0x30, self::der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01").self::der(0x05, '')).self::der(0x03, "\0".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}
