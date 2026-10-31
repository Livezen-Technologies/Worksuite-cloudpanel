<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * SVC-7: "Sign in with Google / GitHub / Microsoft / OpenID Connect" for existing accounts.
 * Authorisation code flow with PKCE and a one-time state; the provider must vouch for the
 * email (verified). No account is created here: the email must already belong to a user.
 */
class SsoService
{
    public const PROVIDERS = ['google_login' => 'Google', 'github_login' => 'GitHub', 'microsoft_login' => 'Microsoft', 'oidc' => 'Single sign-on'];

    public function __construct(private PlatformServices $services) {}

    public function enabled(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $label) {
            $s = $this->services->get($key);
            if ($s && ! empty($s['config']['client_id']) && ! empty($s['credentials']['client_secret'])) {
                $out[] = ['key' => $key, 'label' => $label];
            }
        }

        return $out;
    }

    public function redirectUri(): string
    {
        return rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/').'/auth/sso/callback';
    }

    private function settings(string $key): array
    {
        $s = array_key_exists($key, self::PROVIDERS) ? $this->services->get($key) : null;
        if (! $s || empty($s['config']['client_id']) || empty($s['credentials']['client_secret'])) {
            throw new ApiException('not_found', 'That sign-in option is not set up.', 404);
        }

        return $s;
    }

    /** authorize, token and userinfo URLs for a provider. */
    private function endpoints(string $key, array $cfg): array
    {
        return match ($key) {
            'google_login' => ['https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://openidconnect.googleapis.com/v1/userinfo', 'openid email profile'],
            'github_login' => ['https://github.com/login/oauth/authorize', 'https://github.com/login/oauth/access_token', 'https://api.github.com/user/emails', 'read:user user:email'],
            'microsoft_login' => (function () use ($cfg) {
                $t = preg_match('/^[A-Za-z0-9.-]{1,64}$/', (string) ($cfg['tenant_id'] ?? '')) ? $cfg['tenant_id'] : 'common';

                return ["https://login.microsoftonline.com/{$t}/oauth2/v2.0/authorize", "https://login.microsoftonline.com/{$t}/oauth2/v2.0/token", 'https://graph.microsoft.com/oidc/userinfo', 'openid email profile'];
            })(),
            'oidc' => (function () use ($cfg) {
                $d = Cache::remember('oidc:'.md5((string) ($cfg['issuer'] ?? '')), 3600, fn () => Http::timeout(8)->get(rtrim((string) ($cfg['issuer'] ?? ''), '/').'/.well-known/openid-configuration')->throw()->json());

                return [$d['authorization_endpoint'], $d['token_endpoint'], $d['userinfo_endpoint'], 'openid email profile'];
            })(),
        };
    }

    /** Where to send the browser; the state is single-use and expires after 10 minutes. */
    public function start(string $key): string
    {
        $s = $this->settings($key);
        [$auth, , , $scope] = $this->endpoints($key, $s['config']);
        $state = Str::random(40);
        $verifier = Str::random(64);
        Cache::put("sso:state:{$state}", ['key' => $key, 'verifier' => $verifier], now()->addMinutes(10));

        return $auth.'?'.http_build_query([
            'client_id' => $s['config']['client_id'], 'redirect_uri' => $this->redirectUri(), 'response_type' => 'code', 'scope' => $scope,
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    /** Exchanges the code and returns the existing user whose verified email the provider gave. */
    public function finish(string $state, string $code): User
    {
        $st = Cache::pull("sso:state:{$state}");
        if (! $st) {
            throw ApiException::validation(['state' => ['This sign-in link has expired. Start again.']]);
        }
        $key = $st['key'];
        $s = $this->settings($key);
        [, $tokenUrl, $infoUrl] = $this->endpoints($key, $s['config']);
        $tok = Http::asForm()->acceptJson()->timeout(10)->post($tokenUrl, [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri(),
            'client_id' => $s['config']['client_id'], 'client_secret' => $s['credentials']['client_secret'], 'code_verifier' => $st['verifier'],
        ])->json();
        $access = $tok['access_token'] ?? null;
        if (! $access) {
            throw new ApiException('unauthenticated', (self::PROVIDERS[$key]).' did not accept the sign-in.', 401);
        }
        $email = $this->verifiedEmail($key, (string) $access, $infoUrl);
        if (! $email) {
            throw new ApiException('unauthenticated', (self::PROVIDERS[$key]).' did not share a verified email address.', 401);
        }
        $allowed = array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', (string) ($s['config']['allowed_domains'] ?? ''))));
        if ($allowed && ! in_array(substr(strrchr($email, '@'), 1), $allowed, true)) {
            throw new ApiException('unauthenticated', 'Accounts from that email domain cannot sign in this way.', 403);
        }
        $user = User::where('email', $email)->first();
        if (! $user) {
            throw new ApiException('unauthenticated', "There is no account for {$email}. Sign in with your password or ask for an invitation.", 401);
        }

        return $user;
    }

    private function verifiedEmail(string $key, string $access, string $infoUrl): ?string
    {
        if ($key === 'github_login') {
            $emails = Http::withToken($access)->acceptJson()->timeout(10)->get($infoUrl)->json() ?? [];
            $primary = collect($emails)->first(fn ($e) => ($e['primary'] ?? false) && ($e['verified'] ?? false));

            return $primary ? strtolower((string) $primary['email']) : null;
        }
        $info = Http::withToken($access)->acceptJson()->timeout(10)->get($infoUrl)->json() ?? [];
        $email = strtolower((string) ($info['email'] ?? ''));
        // Microsoft work accounts do not send email_verified; their directory owns the address.
        $verified = $key === 'microsoft_login' ? $email !== '' : filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $verified && $email !== '' ? $email : null;
    }
}
