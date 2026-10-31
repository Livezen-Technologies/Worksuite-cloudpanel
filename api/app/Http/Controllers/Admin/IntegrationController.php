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
            'provider' => [$req, \Illuminate\Validation\Rule::in(array_unique(array_merge(['proxmox'], array_keys(config('integrations.services')))))],
            'credentials' => 'nullable|array',
            'config' => 'nullable|array',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
