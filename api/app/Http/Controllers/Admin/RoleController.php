<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return $this->data(Role::withCount('permissions')->orderBy('label')->get()->map(fn ($r) => [
            'name' => $r->name, 'label' => $r->label, 'scope' => $r->scope, 'description' => $r->description, 'permission_count' => $r->permissions_count,
        ]));
    }

    /** USR-002: permission matrix of one role. */
    public function show(string $name)
    {
        $role = Role::with('permissions')->where('name', $name)->firstOrFail();
        $granted = $role->permissions->keyBy('name');

        return $this->data([
            'name' => $role->name, 'label' => $role->label, 'scope' => $role->scope,
            'permissions' => Permission::orderBy('group')->get()->map(fn ($p) => [
                'name' => $p->name, 'label' => $p->label, 'group' => $p->group,
                'granted' => $granted->has($p->name),
                'constraint' => $granted[$p->name]->pivot->constraint ?? null,
                'requires_approval' => (bool) ($granted[$p->name]->pivot->requires_approval ?? false),
            ]),
        ]);
    }
}
