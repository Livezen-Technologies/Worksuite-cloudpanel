<?php

namespace App\Services\Ai;

use App\Models\AccessToken;
use App\Models\OAuthClient;
use App\Models\OAuthCode;
use App\Models\OAuthRefreshToken;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Str;

/**
 * UAI: OAuth 2.1 for MCP clients, as the MCP authorization spec expects: dynamic client
 * registration, authorization code with PKCE (S256 only), short-lived MCP-only access tokens
 * and rotating refresh tokens. A person approves each client in the panel.
 */
class McpOAuthService
{
    public const ACCESS_TTL = 3600;

    public const REFRESH_DAYS = 30;

    public function resource(): string
    {
        return url('/api/v1/app/mcp');
    }

    public function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    /** https anywhere, or http only to a loopback address (native apps, RFC 8252). No fragments. */
    public static function validRedirect(string $uri): bool
    {
        $p = parse_url($uri);
        if (! $p || isset($p['fragment']) || empty($p['scheme']) || strlen($uri) > 2000) {
            return false;
        }
        if ($p['scheme'] === 'https' && ! empty($p['host'])) {
            return true;
        }
        if ($p['scheme'] === 'http' && in_array($p['host'] ?? '', ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            return true;
        }

        // Private-use schemes for desktop apps (RFC 8252 §7.1): reverse-domain style, or a known AI client.
        $scheme = strtolower($p['scheme']);
        if (in_array($scheme, ['http', 'https', 'javascript', 'data', 'file', 'vbscript', 'blob'], true)) {
            return false;
        }

        return (bool) preg_match('/^[a-z][a-z0-9+-]*(\.[a-z0-9+-]+)+$/', $scheme) || in_array($scheme, ['cursor', 'vscode', 'vscode-insiders', 'claude', 'windsurf', 'zed'], true);
    }

    public function register(string $name, array $redirectUris): OAuthClient
    {
        return OAuthClient::create(['client_id' => 'mcp_'.Str::random(32), 'name' => mb_substr($name, 0, 120), 'redirect_uris' => array_values($redirectUris)]);
    }

    public function client(string $clientId, string $redirectUri): ?OAuthClient
    {
        $c = OAuthClient::where('client_id', $clientId)->first();

        return $c && in_array($redirectUri, (array) $c->redirect_uris, true) ? $c : null;
    }

    public function issueCode(OAuthClient $c, User $user, string $redirectUri, string $challenge, bool $write): string
    {
        $code = Str::random(48);
        OAuthCode::create(['code_hash' => hash('sha256', $code), 'client_id' => $c->client_id, 'user_id' => $user->id, 'organization_id' => $user->current_organization_id,
            'redirect_uri' => $redirectUri, 'code_challenge' => $challenge, 'write' => $write, 'expires_at' => now()->addMinutes(5)]);
        app(AuditLogger::class)->log('ai.oauth.approve', 'oauth_client', $c->id, ['client' => $c->name, 'write' => $write], $user->current_organization_id, $user->id);

        return $code;
    }

    /** @return array|string the token response, or an OAuth error code */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $verifier): array|string
    {
        $row = OAuthCode::where('code_hash', hash('sha256', $code))->first();
        if (! $row || $row->client_id !== $clientId || $row->redirect_uri !== $redirectUri || $row->expires_at->isPast()) {
            return 'invalid_grant';
        }
        if ($row->used_at) {
            // A code used twice means it leaked: revoke everything it produced (OAuth 2.1 §4.1.2).
            $this->revokeFamily($row->client_id, $row->user_id);

            return 'invalid_grant';
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (! preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || ! hash_equals($row->code_challenge, $expected)) {
            return 'invalid_grant';
        }
        $row->forceFill(['used_at' => now()])->save();

        return $this->issueTokens($row->client_id, $row->user_id, $row->organization_id, $row->write);
    }

    public function refresh(string $token, string $clientId): array|string
    {
        $row = OAuthRefreshToken::where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->client_id !== $clientId || $row->expires_at->isPast()) {
            return 'invalid_grant';
        }
        if ($row->revoked_at) {
            $this->revokeFamily($row->client_id, $row->user_id); // a rotated token came back: treat as stolen

            return 'invalid_grant';
        }
        // Revoked in the panel (its access token deleted): the client must sign in again.
        if (! $row->access_token_id || ! AccessToken::whereKey($row->access_token_id)->exists()) {
            $row->forceFill(['revoked_at' => now()])->save();

            return 'invalid_grant';
        }
        $row->forceFill(['revoked_at' => now()])->save();
        AccessToken::whereKey($row->access_token_id)->delete();

        return $this->issueTokens($row->client_id, $row->user_id, $row->organization_id, $row->write);
    }

    private function issueTokens(string $clientId, string $userId, string $orgId, bool $write): array|string
    {
        $user = User::find($userId);
        $client = OAuthClient::where('client_id', $clientId)->first();
        if (! $user || ! $client) {
            return 'invalid_grant';
        }
        $t = $user->createToken('mcp:'.$client->name, $write ? ['mcp', 'mcp.write'] : ['mcp'], now()->addSeconds(self::ACCESS_TTL));
        $t->accessToken->forceFill(['organization_id' => $orgId])->save();
        $refresh = Str::random(64);
        OAuthRefreshToken::create(['token_hash' => hash('sha256', $refresh), 'client_id' => $clientId, 'user_id' => $userId, 'organization_id' => $orgId,
            'access_token_id' => $t->accessToken->id, 'write' => $write, 'expires_at' => now()->addDays(self::REFRESH_DAYS)]);

        return ['access_token' => $t->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => $write ? 'mcp mcp.write' : 'mcp'];
    }

    private function revokeFamily(string $clientId, string $userId): void
    {
        $rows = OAuthRefreshToken::where('client_id', $clientId)->where('user_id', $userId)->get();
        AccessToken::whereIn('id', $rows->pluck('access_token_id')->filter())->delete();
        OAuthRefreshToken::whereIn('id', $rows->pluck('id'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
