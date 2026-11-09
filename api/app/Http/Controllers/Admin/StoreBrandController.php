<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\StoreBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** STORE-001. Product brands. */
class StoreBrandController extends CrudController
{
    protected string $model = StoreBrand::class;

    protected array $searchable = ['name', 'slug'];

    protected array $filters = ['active'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $org = app(\App\Support\Tenant::class)->id();

        return [
            'name' => "{$req}|string|max:120",
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('store_brands', 'slug')->where('organization_id', $org)->ignore($model?->getKey())],
            'logo_url' => 'nullable|url|max:500',
            'website' => 'nullable|url|max:255',
            'active' => 'sometimes|boolean',
        ];
    }

    protected function prepare(array $data, ?Model $model = null): array
    {
        if (array_key_exists('slug', $data) || ! $model) {
            $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name'] ?? $model?->name);
        }

        return $data;
    }

    protected function present(Model $model): array
    {
        return $model->toArray() + ['products_count' => $model->products()->count()];
    }
}
