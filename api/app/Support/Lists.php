<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** List convention: ?search=&sort=field|-field&page=&per_page= plus filters. */
final class Lists
{
    public static function paginate(Builder $query, Request $request, array $searchable = ['name'], array $sortable = ['created_at', 'name'], array $filters = [], ?callable $map = null): array
    {
        if (($search = trim((string) $request->query('search', ''))) !== '' && $searchable) {
            $query->where(function (Builder $q) use ($searchable, $search) {
                $term = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $search)).'%';
                foreach ($searchable as $column) {
                    $q->orWhereRaw('LOWER(CAST('.$q->getGrammar()->wrap($column).' AS TEXT)) LIKE ?', [$term]);
                }
            });
        }
        foreach ($filters as $param => $column) {
            if (is_int($param)) {
                $param = $column;
            }
            $value = $request->query($param);
            if ($value !== null && $value !== '' && $value !== 'all') {
                is_callable($column) ? $column($query, $value) : $query->where($column, $value);
            }
        }
        $sort = (string) $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        if (in_array($field, $sortable, true)) {
            $query->orderBy($field, $direction);
        } else {
            $query->latest();
        }
        $perPage = max(1, min(200, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $items = collect($paginator->items());

        return [
            'data' => ($map ? $items->map($map) : $items)->values(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ];
    }
}
