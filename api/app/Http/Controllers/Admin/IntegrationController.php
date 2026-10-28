<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\Integration;
use Illuminate\Database\Eloquent\Model;

/** SET-003: provider integrations; credentials are write-only. */
class IntegrationController extends CrudController
{
    protected string $model = Integration::class;

    protected array $filters = ['provider', 'enabled'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'provider' => "{$req}|in:hetzner,digitalocean,vultr,linode,aws,proxmox,github,gitlab,gitea,cloudflare,route53,stripe,oidc",
            'credentials' => 'nullable|array',
            'config' => 'nullable|array',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
