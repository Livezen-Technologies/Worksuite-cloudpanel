<?php

namespace App\Drivers;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Tunnel (cfd_tunnel) API with a token that also has
 * Account:Cloudflare Tunnel:Edit. Tunnels are remotely managed: the ingress
 * rules live at Cloudflare and cloudflared only needs the tunnel token.
 */
class CloudflareTunnelApi
{
    public function __construct(private string $token, private string $accountId) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(CloudflareDnsProvider::BASE."/accounts/{$this->accountId}")->withToken($this->token)->acceptJson()->timeout(20);
    }

    private function result(Response $response): mixed
    {
        $body = $response->json() ?? [];
        if (! $response->successful() || ! ($body['success'] ?? false)) {
            $message = collect($body['errors'] ?? [])->map(fn ($e) => $e['message'] ?? '')->filter()->implode('; ');
            throw ApiException::upstream('Cloudflare Tunnel: '.($message ?: 'HTTP '.$response->status()));
        }

        return $body['result'] ?? null;
    }

    /** @return array{id:string, name:string} */
    public function create(string $name): array
    {
        $t = $this->result($this->http()->post('/cfd_tunnel', ['name' => $name, 'config_src' => 'cloudflare']));

        return ['id' => $t['id'], 'name' => $t['name']];
    }

    public function token(string $id): string
    {
        return (string) $this->result($this->http()->get("/cfd_tunnel/{$id}/token"));
    }

    /** @return array{status:string, connections:int} healthy|degraded|down|inactive */
    public function status(string $id): array
    {
        $t = $this->result($this->http()->get("/cfd_tunnel/{$id}"));

        return ['status' => (string) ($t['status'] ?? 'inactive'), 'connections' => count((array) ($t['connections'] ?? []))];
    }

    /** @param list<array{hostname:string, service:string}> $ingress */
    public function configure(string $id, array $ingress): void
    {
        $rules = array_values($ingress);
        $rules[] = ['service' => 'http_status:404']; // catch-all, required last
        $this->result($this->http()->put("/cfd_tunnel/{$id}/configurations", ['config' => ['ingress' => $rules]]));
    }

    public function delete(string $id): void
    {
        $this->result($this->http()->delete("/cfd_tunnel/{$id}?cascade=true"));
    }
}
