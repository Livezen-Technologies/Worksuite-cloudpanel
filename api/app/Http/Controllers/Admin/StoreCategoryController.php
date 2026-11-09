<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\StoreCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** STORE-001. Product categories; one level of nesting via parent_id. */
class StoreCategoryController extends CrudController
{
    protected string $model = StoreCategory::class;

    protected array $searchable = ['name', 'slug'];

    protected array $sortable = ['name', 'sort', 'created_at', 'updated_at'];

    protected array $filters = ['active', 'parent_id'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $org = app(\App\Support\Tenant::class)->id();

        return [
            'name' => "{$req}|string|max:120",
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('store_categories', 'slug')->where('organization_id', $org)->ignore($model?->getKey())],
            'parent_id' => ['nullable', 'uuid', Rule::exists('store_categories', 'id')->where('organization_id', $org)],
            'description' => 'nullable|string|max:2000',
            'image_url' => 'nullable|url|max:500',
            'sort' => 'sometimes|integer|min:0|max:100000',
            'active' => 'sometimes|boolean',
        ];
    }

    protected function prepare(array $data, ?Model $model = null): array
    {
        if ($model && ($data['parent_id'] ?? null) === $model->getKey()) {
            throw ValidationException::withMessages(['parent_id' => ['A category cannot be its own parent.']]);
        }
        if (array_key_exists('slug', $data) || ! $model) {
            $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name'] ?? $model?->name);
        }

        return $data;
    }

    protected function present(Model $model): array
    {
        return $model->toArray() + [
            'parent_name' => $model->parent?->name,
            'products_count' => $model->products()->count(),
        ];
    }
}
