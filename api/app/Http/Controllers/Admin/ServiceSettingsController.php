<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * SVC-1..4: set up third-party services from one place. Each service has a fixed list of
 * fields; secrets are encrypted, never returned (only "set" and the last 4 characters),
 * and left unchanged when a save leaves them blank. Some services have a connection test.
 */
class ServiceSettingsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    private function def(string $key): array
    {
        $d = config("integrations.services.{$key}") ?? abort(404);

        return ['key' => $key, 'label' => $d[0], 'category' => $d[1], 'description' => $d[2], 'test' => $d[4] !== null,
            'fields' => array_map(fn ($f) => ['key' => $f[0], 'label' => $f[1], 'kind' => str_starts_with($f[2], 'select:') ? 'select' : $f[2],
                'options' => str_starts_with($f[2], 'select:') ? explode(',', substr($f[2], 7)) : null], $d[3])];
    }

    private function find(string $key): ?Integration
    {
        return Integration::where('provider', $key)->first();
    }

    private function present(string $key): array
    {
        $def = $this->def($key);
        $row = $this->find($key);
        $creds = $row?->credentials ?? [];
        $config = $row?->config ?? [];
        $values = [];
        foreach ($def['fields'] as $f) {
            $v = $f['kind'] === 'secret' ? ($creds[$f['key']] ?? null) : ($config[$f['key']] ?? null);
            $values[$f['key']] = $f['kind'] === 'secret' ? ($v ? ['set' => true, 'hint' => '••••'.substr((string) $v, -4)] : ['set' => false]) : $v;
        }

        return $def + ['configured' => (bool) $row, 'enabled' => (bool) ($row?->enabled), 'values' => $values,
            'last_test' => $config['_last_test'] ?? null, 'updated_at' => $row?->updated_at?->toIso8601ZuluString()];
    }

    public function index()
    {
        return $this->data(['categories' => config('integrations.categories'),
            'services' => collect(array_keys(config('integrations.services')))->map(fn ($k) => $this->present($k))->values()]);
    }

    public function update(Request $request, string $key)
    {
        $def = $this->def($key);
        $rules = ['enabled' => 'sometimes|boolean', 'values' => 'sometimes|array'];
        foreach ($def['fields'] as $f) {
            $rules["values.{$f['key']}"] = match ($f['kind']) {
                'select' => ['nullable', 'in:'.implode(',', $f['options'])],
                'url' => ['nullable', 'url:https,http', 'max:500'],
                default => ['nullable', 'string', 'max:4000'],
            };
        }
        $v = $request->validate($rules);
        $row = $this->find($key) ?? new Integration(['provider' => $key, 'name' => $def['label'], 'enabled' => false]);
        $creds = $row->credentials ?? [];
        $config = $row->config ?? [];
        foreach ($def['fields'] as $f) {
            if (! array_key_exists($f['key'], $v['values'] ?? [])) {
                continue;
            }
            $val = $v['values'][$f['key']];
            if ($f['kind'] === 'secret') {
                if ($val !== null && $val !== '') {
                    $creds[$f['key']] = trim($val); // blank keeps the stored secret
                }
            } else {
                $config[$f['key']] = $val === '' ? null : $val;
            }
        }
        $row->credentials = $creds;
        $row->config = $config;
        if (array_key_exists('enabled', $v)) {
            $row->enabled = $v['enabled'];
        }
        $row->save();
        $this->audit->log('service.update', 'integrations', $row->id, ['provider' => $key, 'enabled' => $row->enabled, 'fields' => array_keys($v['values'] ?? [])]);

        return $this->data($this->present($key));
    }

    public function destroy(string $key)
    {
        $this->def($key);
        $row = $this->find($key);
        if ($row) {
            $row->delete();
            $this->audit->log('service.remove', 'integrations', $row->id, ['provider' => $key]);
        }

        return $this->data($this->present($key));
    }

    /** SVC-3: a read-only call that proves the credentials work. */
    public function test(string $key)
    {
        $d = config("integrations.services.{$key}") ?? abort(404);
        $row = $this->find($key) ?? throw ApiException::conflict('Save the settings first.');
        $c = $row->credentials ?? [];
        $cfg = $row->config ?? [];
        try {
            [$ok, $msg] = match ($d[4]) {
                'stripe' => $this->ok(Http::withToken($c['secret_key'] ?? '')->timeout(10)->get('https://api.stripe.com/v1/balance'), 'Stripe accepted the secret key.'),
                'paypal' => $this->ok(Http::asForm()->withBasicAuth($cfg['client_id'] ?? '', $c['client_secret'] ?? '')->timeout(10)
                    ->post((($cfg['mode'] ?? 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com').'/v1/oauth2/token', ['grant_type' => 'client_credentials']), 'PayPal issued a token.'),
                'cloudflare' => $this->ok(Http::withToken($c['api_token'] ?? '')->timeout(10)->get('https://api.cloudflare.com/client/v4/user/tokens/verify'), 'Cloudflare says the token is active.'),
                'github' => $this->ok(Http::withToken($c['token'] ?? '')->timeout(10)->get('https://api.github.com/user'), 'GitHub accepted the token.'),
                'hetzner' => $this->ok(Http::withToken($c['api_token'] ?? '')->timeout(10)->get('https://api.hetzner.cloud/v1/locations'), 'Hetzner accepted the token.'),
                'digitalocean' => $this->ok(Http::withToken($c['api_token'] ?? '')->timeout(10)->get('https://api.digitalocean.com/v2/account'), 'DigitalOcean accepted the token.'),
                'postmark' => $this->ok(Http::withHeaders(['X-Postmark-Server-Token' => $c['server_token'] ?? '', 'Accept' => 'application/json'])->timeout(10)->get('https://api.postmarkapp.com/server'), 'Postmark accepted the token.'),
                'oidc' => $this->ok(Http::timeout(10)->get(rtrim((string) ($cfg['issuer'] ?? ''), '/').'/.well-known/openid-configuration'), 'The issuer publishes its OpenID configuration.'),
                'slack' => $this->ok(Http::timeout(10)->post($c['webhook_url'] ?? '', ['text' => 'WorkSuite test message: Slack is connected.']), 'A test message was sent to Slack.'),
                'telegram' => $this->ok(Http::timeout(10)->post('https://api.telegram.org/bot'.($c['bot_token'] ?? '').'/sendMessage', ['chat_id' => $cfg['chat_id'] ?? '', 'text' => 'WorkSuite test message: Telegram is connected.']), 'A test message was sent to Telegram.'),
                default => throw ApiException::conflict('This service has no connection test.'),
            };
        } catch (\Illuminate\Http\Client\ConnectionException) {
            [$ok, $msg] = [false, 'Could not reach the service. Check the server’s outbound network.'];
        }
        $row->config = ($row->config ?? []) + [];
        $row->config = array_merge($row->config, ['_last_test' => ['ok' => $ok, 'message' => $msg, 'at' => now()->toIso8601ZuluString()]]);
        $row->save();

        return $this->data(['ok' => $ok, 'message' => $msg]);
    }

    private function ok($res, string $good): array
    {
        if ($res->successful()) {
            return [true, $good];
        }

        return [false, "The service answered {$res->status()}: ".mb_substr((string) ($res->json('error.message') ?? $res->json('message') ?? $res->json('errors.0.message') ?? $res->json('error_description') ?? 'credentials were not accepted'), 0, 200)];
    }
}
