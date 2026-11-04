<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\GitConnection;
use App\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * GIT: OAuth sign-in for GitHub and GitLab. The state is encrypted (organisation, user,
 * where to return) and single-use, so a callback can't be replayed or forged into
 * another organisation.
 */
class GitOAuthService
{
    public function available(): array
    {
        return collect(config('platform.git_oauth'))->map(fn ($c) => ! empty($c['client_id']) && ! empty($c['client_secret']))->all();
    }

    private function cfg(string $provider): array
    {
        $c = config("platform.git_oauth.{$provider}");
        if (! $c || empty($c['client_id']) || empty($c['client_secret'])) {
            throw ApiException::conflict("Sign-in with {$provider} is not set up. Add its OAuth app id and secret to the environment, or connect with a token.");
        }

        return $c;
    }

    public function redirectUri(string $provider): string
    {
        return url("/api/v1/git/oauth/{$provider}/callback");
    }

    public function start(string $provider, string $orgId, string $userId, string $returnTo): string
    {
        $c = $this->cfg($provider);
        $nonce = Str::random(32);
        Cache::put("git-oauth:{$nonce}", true, now()->addMinutes(10));
        $state = Crypt::encryptString(json_encode(['p' => $provider, 'o' => $orgId, 'u' => $userId, 'r' => $returnTo, 'n' => $nonce, 'e' => now()->addMinutes(10)->timestamp]));

        return $c['authorize'].'?'.http_build_query(['client_id' => $c['client_id'], 'redirect_uri' => $this->redirectUri($provider), 'scope' => $c['scope'], 'state' => $state, 'response_type' => 'code']);
    }

    /** @return array{connection: GitConnection, return: string} */
    public function finish(string $provider, string $code, string $state): array
    {
        $c = $this->cfg($provider);
        try {
            $s = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ApiException::validation(['state' => ['This sign-in link is not valid.']]);
        }
        if (($s['p'] ?? null) !== $provider || ($s['e'] ?? 0) < now()->timestamp || ! Cache::pull('git-oauth:'.($s['n'] ?? ''))) {
            throw ApiException::validation(['state' => ['This sign-in link has expired or was already used. Start again.']]);
        }
        $r = Http::asForm()->acceptJson()->timeout(10)->post($c['token'], [
            'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'code' => $code,
            'redirect_uri' => $this->redirectUri($provider), 'grant_type' => 'authorization_code',
        ]);
        $token = (string) $r->json('access_token', '');
        if (! $r->successful() || $token === '') {
            throw ApiException::conflict("{$provider} did not accept the sign-in. Try again.");
        }
        $base = $provider === 'gitlab' && ! str_starts_with($c['authorize'], 'https://gitlab.com/') ? preg_replace('#/oauth/authorize$#', '', $c['authorize']) : null;
        $conn = app(Tenant::class)->as($s['o'], function () use ($provider, $token, $base, $s) {
            $conn = app(GitProviderService::class)->verify(GitConnection::create(['provider' => $provider, 'base_url' => $base, 'token' => $token, 'status' => 'connected']));
            app(AuditLogger::class)->log('git.connection.oauth', 'git_connection', $conn->id, ['provider' => $provider, 'account' => $conn->account], $s['o'], $s['u']);

            return $conn;
        });

        return ['connection' => $conn, 'return' => (string) $s['r']];
    }
}
