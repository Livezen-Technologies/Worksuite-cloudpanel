<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CrudController;
use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Model;

class MaintenanceWindowController extends CrudController
{
    protected string $model = MaintenanceWindow::class;

    protected array $sortable = ['starts_at', 'ends_at', 'name', 'created_at'];

    protected array $filters = ['server_id', 'server_group_id'];

    protected function rules(bool $creating, ?Model $model = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "{$req}|string|max:120",
            'server_id' => 'nullable|uuid|exists:servers,id',
            'server_group_id' => 'nullable|uuid|exists:server_groups,id',
            'starts_at' => "{$req}|date",
            'ends_at' => "{$req}|date|after:starts_at",
            'suppress_alerts' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    protected function present(Model $w): array
    {
        return $w->toArray() + ['active' => $w->starts_at->isPast() && $w->ends_at->isFuture()];
    }
}
