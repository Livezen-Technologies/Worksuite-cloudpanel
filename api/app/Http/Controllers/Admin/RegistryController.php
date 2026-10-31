<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\Registry;
use Illuminate\Database\Eloquent\Model;

/** IMG-002: credentials are write-only and encrypted at rest. */
class RegistryController extends CrudController
{
    protected string $model = Registry::class;

    protected array $filters = ['type'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'type' => "{$req}|in:dockerhub,ghcr,private",
            'url' => "{$req}|string|max:255",
            'username' => 'nullable|string|max:190',
            'password' => 'nullable|string|max:4096',
        ];
    }

    protected function prepare(array $data, ?Model $model = null): array
    {
        if (array_key_exists('password', $data)) {
            $data['credentials'] = $data['password'];
            unset($data['password']);
        }

        return $data;
    }

    /** BLD: preview or run the registry clean-up for this registry. */
    public function gc(\Illuminate\Http\Request $request, string $id)
    {
        $v = $request->validate(['dry_run' => 'sometimes|boolean', 'keep' => 'sometimes|integer|min:1|max:50']);
        $rows = app(\App\Services\RegistryGcService::class)->collect(\App\Models\Registry::findOrFail($id), (int) ($v['keep'] ?? config('platform.builds.registry_keep', 5)), (bool) ($v['dry_run'] ?? true));

        return $this->data(['images' => $rows, 'deleted' => collect($rows)->where('deleted', true)->count()]);
    }
}
