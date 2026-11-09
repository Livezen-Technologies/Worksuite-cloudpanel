<?php

namespace App\Http\Controllers;

use App\Models\OAuthClient;
use App\Services\Ai\McpOAuthService;
use Illuminate\Http\Request;

/** UAI: the OAuth endpoints MCP clients discover and use (RFC 9728, 8414, 7591, OAuth 2.1). */
class McpOAuthController extends Controller
{
    public function __construct(private McpOAuthService $oauth) {}

    public function protectedResource()
    {
        return response()->json([
            'resource' => $this->oauth->resource(),
            'authorization_servers' => [$this->oauth->issuer()],
            'scopes_supported' => ['mcp', 'mcp.write'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => config('app.name').' MCP',
        ]);
    }

    public function authorizationServer()
    {
        $i = $this->oauth->issuer();

        return response()->json([
            'issuer' => $i,
            'authorization_endpoint' => $i.'/oauth/authorize',
            'token_endpoint' => $i.'/oauth/token',
            'registration_endpoint' => $i.'/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp', 'mcp.write'],
        ]);
    }

    /** RFC 7591 dynamic client registration: public clients only (PKCE, no secret). */
    public function register(Request $request)
    {
        $uris = (array) $request->input('redirect_uris', []);
        if ($uris === [] || count($uris) > 10 || array_filter($uris, fn ($u) => ! is_string($u) || ! McpOAuthService::validRedirect($u))) {
            return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => 'Use https, http to localhost, or an app scheme.'], 400);
        }
        $name = trim((string) $request->input('client_name', 'MCP client')) ?: 'MCP client';
        $c = $this->oauth->register($name, $uris);

        return response()->json(['client_id' => $c->client_id, 'client_name' => $c->name, 'redirect_uris' => $c->redirect_uris,
            'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
            'client_id_issued_at' => $c->created_at?->timestamp], 201);
    }

    /** Checks the request, then hands the browser to the panel's consent page, where the person signs in. */
    public function authorize(Request $request)
    {
        $client = $this->oauth->client((string) $request->query('client_id'), (string) $request->query('redirect_uri'));
        if (! $client) {
            // Never redirect to an address we can't vouch for.
            return response('Unknown client or redirect address.', 400);
        }
        $back = fn (string $error) => redirect()->away($request->query('redirect_uri').(str_contains($request->query('redirect_uri'), '?') ? '&' : '?')
            .http_build_query(array_filter(['error' => $error, 'state' => $request->query('state')])));
        if ($request->query('response_type') !== 'code') {
            return $back('unsupported_response_type');
        }
        if ($request->query('code_challenge_method') !== 'S256' || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', (string) $request->query('code_challenge'))) {
            return $back('invalid_request');
        }
        if ($request->filled('resource') && rtrim((string) $request->query('resource'), '/') !== $this->oauth->resource()) {
            return $back('invalid_target');
        }
        $panel = rtrim((string) (config('platform.panel_url') ?: config('app.url')), '/');

        return redirect()->away($panel.'/oauth/consent?'.http_build_query($request->only(['client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'])));
    }

    /** The consent page reads who is asking from here, not from its own query string. */
    public function consentInfo(Request $request)
    {
        $c = $this->oauth->client((string) $request->query('client_id'), (string) $request->query('redirect_uri'));
        if (! $c) {
            throw \App\Exceptions\ApiException::validation(['client_id' => ['Unknown client or redirect address.']]);
        }

        return $this->data(['name' => $c->name, 'redirect_host' => parse_url($c->redirect_uris[0], PHP_URL_HOST) ?: parse_url($c->redirect_uris[0], PHP_URL_SCHEME), 'resource' => $this->oauth->resource()]);
    }

    /** The signed-in person approves or denies; the answer is where the browser goes next. */
    public function consent(Request $request)
    {
        $v = $request->validate([
            'client_id' => 'required|string', 'redirect_uri' => 'required|string', 'code_challenge' => ['required', 'regex:/^[A-Za-z0-9_-]{43,128}$/'],
            'state' => 'nullable|string|max:500', 'approve' => 'required|boolean', 'write' => 'sometimes|boolean',
        ]);
        $c = $this->oauth->client($v['client_id'], $v['redirect_uri']);
        if (! $c) {
            throw \App\Exceptions\ApiException::validation(['client_id' => ['Unknown client or redirect address.']]);
        }
        $user = $request->user();
        // Consent comes from a person in the panel, never from an MCP token granting itself more.
        $token = $user->currentAccessToken();
        if ($token && method_exists($token, 'can') && ! $token->can('*') && ! $token->can('app.manage')) {
            throw \App\Exceptions\ApiException::forbidden('Approve AI clients from the panel.');
        }
        if (! $user->grant('app.manage')) {
            throw \App\Exceptions\ApiException::forbidden('Your role does not allow managing apps.');
        }
        $sep = str_contains($v['redirect_uri'], '?') ? '&' : '?';
        if (! $v['approve']) {
            return $this->data(['redirect' => $v['redirect_uri'].$sep.http_build_query(array_filter(['error' => 'access_denied', 'state' => $v['state'] ?? null]))]);
        }
        $code = $this->oauth->issueCode($c, $user, $v['redirect_uri'], $v['code_challenge'], (bool) ($v['write'] ?? false));

        return $this->data(['redirect' => $v['redirect_uri'].$sep.http_build_query(array_filter(['code' => $code, 'state' => $v['state'] ?? null]))]);
    }

    public function token(Request $request)
    {
        $grant = (string) $request->input('grant_type');
        $r = match ($grant) {
            'authorization_code' => $this->oauth->exchangeCode((string) $request->input('code'), (string) $request->input('client_id'), (string) $request->input('redirect_uri'), (string) $request->input('code_verifier')),
            'refresh_token' => $this->oauth->refresh((string) $request->input('refresh_token'), (string) $request->input('client_id')),
            default => 'unsupported_grant_type',
        };

        return is_string($r)
            ? response()->json(['error' => $r], 400, ['Cache-Control' => 'no-store'])
            : response()->json($r, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
