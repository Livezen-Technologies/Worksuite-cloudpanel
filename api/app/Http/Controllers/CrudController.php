<?php

namespace App\Http\Controllers;

use App\Support\Lists;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Thin generic CRUD for simple tenant resources. Subclasses declare the model,
 * validation rules and list options; route middleware does the RBAC.
 */
abstract class CrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    protected array $searchable = ['name'];

    protected array $sortable = ['name', 'created_at', 'updated_at'];

    protected array $filters = [];

    abstract protected function rules(bool $creating, ?Model $model = null): array;

    protected function prepare(array $data, ?Model $model = null): array
    {
        return $data;
    }

    protected function present(Model $model): array
    {
        return $model->toArray();
    }

    protected function saved(Model $model, bool $created): void {}

    public function index(Request $request)
    {
        return response()->json(Lists::paginate($this->model::query(), $request, $this->searchable, $this->sortable, $this->filters, fn ($m) => $this->present($m)));
    }

    public function store(Request $request)
    {
        $model = $this->model::create($this->prepare($request->validate($this->rules(true))));
        $this->saved($model, true);

        return $this->data($this->present($model->fresh()), 201);
    }

    public function show(string $id)
    {
        return $this->data($this->present($this->find($id)));
    }

    public function update(Request $request, string $id)
    {
        $model = $this->find($id);
        $model->update($this->prepare($request->validate($this->rules(false, $model)), $model));
        $this->saved($model, false);

        return $this->data($this->present($model->fresh()));
    }

    public function destroy(string $id)
    {
        $this->find($id)->delete();

        return response()->noContent();
    }

    protected function find(string $id): Model
    {
        return $this->model::findOrFail($id);
    }
}
