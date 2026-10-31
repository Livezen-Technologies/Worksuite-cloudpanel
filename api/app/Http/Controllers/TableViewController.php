<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\TableView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TBL-4: saved views for any table. Personal views belong to their author; shared views are
 * visible to the organisation and can be changed by their author or anyone with users.manage.
 * One default per person and table (a personal default wins over a shared one).
 */
class TableViewController extends Controller
{
    private const KEY = 'required|string|max:80|regex:/^[a-z0-9][a-z0-9_.-]*$/';

    private function rules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return [
            'name' => "{$r}|string|min:1|max:60",
            'config' => "{$r}|array",
            'config.columns' => 'sometimes|array|max:60',
            'config.columns.*' => 'string|max:80',
            'config.hidden' => 'sometimes|array|max:60',
            'config.widths' => 'sometimes|array|max:60',
            'config.widths.*' => 'numeric|between:40,1200',
            'config.sort' => 'sometimes|array|max:5',
            'config.sort.*.key' => 'required|string|max:80',
            'config.sort.*.dir' => 'required|in:1,-1',
            'config.conditions' => 'sometimes|array|max:20',
            'config.conditions.*.col' => 'required|string|max:80',
            'config.conditions.*.op' => 'required|in:contains,equals,not_equals,starts,gt,lt,empty,not_empty,before,after,in',
            'config.conditions.*.value' => 'nullable',
            'config.filter' => 'sometimes|nullable|string|max:80',
            'config.q' => 'sometimes|nullable|string|max:200',
            'config.density' => 'sometimes|in:compact,comfortable,spacious',
            'config.pageSize' => 'sometimes|integer|in:10,25,50,100',
            'config.layout' => 'sometimes|in:table,list,grid',
            'shared' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ];
    }

    private function visible(Request $request, string $table)
    {
        $uid = $request->user()->id;

        return TableView::where('table_key', $table)->where(fn ($q) => $q->where('user_id', $uid)->orWhere('shared', true));
    }

    private function present(TableView $v, Request $request): array
    {
        return ['id' => $v->id, 'table' => $v->table_key, 'name' => $v->name, 'config' => $v->config, 'shared' => $v->shared,
            'is_default' => $v->is_default, 'mine' => $v->user_id === $request->user()->id, 'can_edit' => $this->canEdit($v, $request),
            'updated_at' => $v->updated_at?->toIso8601ZuluString()];
    }

    private function canEdit(TableView $v, Request $request): bool
    {
        return $v->user_id === $request->user()->id || ($v->shared && $request->user()->hasPermission('users.manage'));
    }

    public function index(Request $request)
    {
        $table = $request->validate(['table' => self::KEY])['table'];

        return $this->data($this->visible($request, $table)->orderByDesc('is_default')->orderBy('name')->get()->map(fn ($v) => $this->present($v, $request))->values());
    }

    public function store(Request $request)
    {
        $v = $request->validate(['table' => self::KEY] + $this->rules(true));
        if (($v['shared'] ?? false) && ! $request->user()->hasPermission('users.manage')) {
            throw ApiException::forbidden('Only people who manage users can share views with the team.');
        }
        if (TableView::where('table_key', $v['table'])->where('user_id', $request->user()->id)->count() >= 50) {
            throw ApiException::conflict('You have 50 saved views for this table. Delete one first.');
        }
        $view = DB::transaction(function () use ($v, $request) {
            if ($v['is_default'] ?? false) {
                $this->clearDefault($request, $v['table'], (bool) ($v['shared'] ?? false));
            }

            return TableView::create(['user_id' => $request->user()->id, 'table_key' => $v['table'], 'name' => $v['name'], 'config' => $v['config'],
                'shared' => (bool) ($v['shared'] ?? false), 'is_default' => (bool) ($v['is_default'] ?? false)]);
        });

        return $this->data($this->present($view, $request), 201);
    }

    public function update(Request $request, string $id)
    {
        $view = $this->visible($request, (string) TableView::findOrFail($id)->table_key)->findOrFail($id);
        if (! $this->canEdit($view, $request)) {
            throw ApiException::forbidden('Only its author or a user manager can change this view.');
        }
        $v = $request->validate($this->rules(false));
        if (array_key_exists('shared', $v) && $v['shared'] !== $view->shared && ! $request->user()->hasPermission('users.manage')) {
            throw ApiException::forbidden('Only people who manage users can share views with the team.');
        }
        DB::transaction(function () use ($v, $view, $request) {
            if ($v['is_default'] ?? false) {
                $this->clearDefault($request, $view->table_key, (bool) ($v['shared'] ?? $view->shared));
            }
            $view->update($v);
        });

        return $this->data($this->present($view->fresh(), $request));
    }

    public function destroy(Request $request, string $id)
    {
        $view = TableView::findOrFail($id);
        if (! $this->canEdit($view, $request) || (! $view->shared && $view->user_id !== $request->user()->id)) {
            abort(404);
        }
        $view->delete();

        return $this->data(['deleted' => true]);
    }

    private function clearDefault(Request $request, string $table, bool $shared): void
    {
        TableView::where('table_key', $table)->where($shared ? fn ($q) => $q->where('shared', true) : fn ($q) => $q->where('user_id', $request->user()->id)->where('shared', false))
            ->update(['is_default' => false]);
    }
}
