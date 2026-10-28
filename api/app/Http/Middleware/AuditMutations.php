<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;

/**
 * Writes an audit event for every successful mutation (SRS 4, LOG-003).
 * Only input field names are recorded, never values (secrets).
 */
class AuditMutations
{
    private const NEVER_LOG = ['password', 'token', 'otp', 'credentials', 'value', 'secret', 'registry_auth', 'config'];

    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->isMethodSafe() || $response->getStatusCode() >= 400 || $request->attributes->get('audit_skip')) {
            return $response;
        }
        $route = $request->route();
        $params = collect($route?->parameters() ?? [])->map(fn ($p) => is_object($p) && method_exists($p, 'getKey') ? $p->getKey() : $p);
        $resourceId = $params->last();
        $data = json_decode((string) $response->getContent(), true)['data'] ?? [];
        $resourceId ??= $data['id'] ?? $data['app']['id'] ?? null;
        $resourceType = $route ? (explode('.', (string) $route->getName())[0] ?: null) : null;

        $this->audit->log(
            $request->attributes->get('audit_action') ?? ($route?->getName() ?: strtolower($request->method()).' '.$request->path()),
            $resourceType,
            is_scalar($resourceId) ? (string) $resourceId : null,
            array_filter([
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status' => $response->getStatusCode(),
                'fields' => array_values(array_diff(array_keys($request->except(self::NEVER_LOG)), [])),
                'job_id' => $data['job_id'] ?? null,
                'approval_id' => $data['approval_id'] ?? null,
            ]),
        );

        return $response;
    }
}
