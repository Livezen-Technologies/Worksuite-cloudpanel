<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\FirewallRule;
use App\Services\FirewallConfigService;
use App\Services\TraefikConfigService;
use Illuminate\Database\Eloquent\Model;

/** FW-001. Rate-limit rules. Every change re-pushes the proxy configuration. */
class FirewallRuleController extends CrudController
{
    protected string $model = FirewallRule::class;

    protected array $filters = ['action', 'enabled', 'domain_id'];

    protected array $sortable = ['name', 'priority', 'hits', 'created_at', 'updated_at'];

    public function __construct(private TraefikConfigService $traefik) {}

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'domain_id' => 'nullable|uuid|exists:domains,id',
            'methods' => 'nullable|array', 'methods.*' => 'string|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS',
            'paths' => "{$req}|array|min:1", 'paths.*' => 'string|max:255',
            'limit' => "{$req}|integer|min:1|max:1000000",
            'window_seconds' => 'sometimes|integer|min:1|max:86400',
            'counted_by' => 'sometimes|in:ip,ip_session,token_else_ip',
            'action' => 'sometimes|in:block,throttle,challenge,recaptcha,log',
            'priority' => 'sometimes|integer|min:1|max:1000',
            'enabled' => 'sometimes|boolean',
        ];
    }

    protected function present(Model $model): array
    {
        /** @var FirewallRule $model */
        return $model->toArray() + [
            'rate' => $model->rate,
            'scope' => $model->domain_id ? ($model->domain?->name ?? 'one domain') : 'all domains',
            'enforced' => in_array($model->action, FirewallConfigService::NATIVE_ACTIONS, true),
        ];
    }

    /**
     * The proxy configuration is content-hashed, so without an explicit push a
     * rule change never reaches Traefik.
     */
    protected function saved(Model $model, bool $created): void
    {
        $this->traefik->push();
    }

    public function destroy(string $id)
    {
        $response = parent::destroy($id);
        $this->traefik->push();

        return $response;
    }
}
