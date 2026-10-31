<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** USR-002: roles and their permission matrix; staff can create, edit, copy and delete custom roles. */
class RoleController extends Controller
{
    public const CONSTRAINTS = ['all', 'own_org', 'assigned', 'read'];

    public function __construct(private AuditLogger $audit, private Rbac $rbac) {}

    private static function isCustom(Role $role): bool
    {
        return ! array_key_exists($role->name, config('rbac.roles'));
    }

    public function index()
    {
        $members = Membership::allTenants()->selectRaw('role, count(*) as n')->groupBy('role')->pluck('n', 'role');

        return $this->data(Role::withCount('permissions')->orderBy('label')->get()->map(fn ($r) => [
            'name' => $r->name, 'label' => $r->label, 'scope' => $r->scope, 'description' => $r->description, 'permission_count' => $r->permissions_count,
            'custom' => self::isCustom($r), 'members' => (int) ($members[$r->name] ?? 0),
        ]));
    }

    public function show(string $name)
    {
        $role = Role::with('permissions')->where('name', $name)->firstOrFail();
        $granted = $role->permissions->keyBy('name');

        return $this->data([
            'name' => $role->name, 'label' => $role->label, 'scope' => $role->scope, 'description' => $role->description, 'custom' => self::isCustom($role),
            'permissions' => Permission::orderBy('group')->get()->map(fn ($p) => [
                'name' => $p->name, 'label' => $p->label, 'group' => $p->group,
                'granted' => $granted->has($p->name),
                'constraint' => $granted[$p->name]->pivot->constraint ?? null,
                'requires_approval' => (bool) ($granted[$p->name]->pivot->requires_approval ?? false),
            ]),
        ]);
    }

    /** Roles are shared by every tenant, so only platform staff may define them. */
    private function guard(Request $request): void
    {
        if (! $this->seesAllTenants($request)) {
            throw ApiException::forbidden('Only platform staff can define roles.');
        }
    }

    private function rules(): array
    {
        return [
            'label' => 'required|string|min:2|max:60',
            'description' => 'nullable|string|max:300',
            'scope' => ['required', Rule::in(['platform', 'customer', 'both'])],
            'permissions' => 'present|array',
            'permissions.*.name' => 'required|string|exists:permissions,name',
            'permissions.*.constraint' => ['required', Rule::in(self::CONSTRAINTS)],
            'permissions.*.requires_approval' => 'boolean',
        ];
    }

    /** Nobody can hand out a permission they do not hold themselves. */
    private function sync(Request $request, Role $role, array $grants): void
    {
        $mine = $this->rbac->permissionsFor($request->user()->roleName());
        $over = array_values(array_diff(array_column($grants, 'name'), $mine));
        if ($over) {
            throw ApiException::validation(['permissions' => ['You can only grant permissions you have yourself: '.implode(', ', $over).'.']]);
        }
        $ids = Permission::whereIn('name', array_column($grants, 'name'))->pluck('id', 'name');
        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        foreach (collect($grants)->keyBy('name') as $g) {
            DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $ids[$g['name']],
                'constraint' => $g['constraint'], 'requires_approval' => (bool) ($g['requires_approval'] ?? false)]);
        }
        $this->rbac->flush();
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $v = $request->validate($this->rules());
        $base = 'custom_'.trim(preg_replace('/[^a-z]+/', '_', Str::lower(Str::ascii($v['label']))), '_');
        $name = $base;
        for ($i = 0; Role::where('name', $name)->exists(); $i++) {
            $name = $base.'_'.str_repeat('x', $i + 1);
        }
        $role = DB::transaction(function () use ($request, $v, $name) {
            $role = Role::create(['name' => $name, 'label' => $v['label'], 'scope' => $v['scope'], 'description' => $v['description'] ?? null]);
            $this->sync($request, $role, $v['permissions']);

            return $role;
        });
        $this->audit->log('role.create', 'roles', $role->id, ['name' => $name, 'permissions' => count($v['permissions'])]);

        return $this->show($role->name)->setStatusCode(201);
    }

    public function update(Request $request, string $name)
    {
        $this->guard($request);
        $role = Role::where('name', $name)->firstOrFail();
        if (! self::isCustom($role)) {
            throw ApiException::conflict('Built-in roles can’t be changed. Copy it to make your own version.');
        }
        $v = $request->validate($this->rules());
        DB::transaction(function () use ($request, $role, $v) {
            $role->update(['label' => $v['label'], 'scope' => $v['scope'], 'description' => $v['description'] ?? null]);
            $this->sync($request, $role, $v['permissions']);
        });
        $this->audit->log('role.update', 'roles', $role->id, ['name' => $name, 'permissions' => count($v['permissions'])]);

        return $this->show($name);
    }

    public function destroy(Request $request, string $name)
    {
        $this->guard($request);
        $role = Role::where('name', $name)->firstOrFail();
        if (! self::isCustom($role)) {
            throw ApiException::conflict('Built-in roles can’t be deleted.');
        }
        $n = Membership::allTenants()->where('role', $name)->count();
        if ($n) {
            throw ApiException::conflict("{$n} ".($n === 1 ? 'person has' : 'people have').' this role. Give them another role first.');
        }
        $role->delete();
        $this->rbac->flush();
        $this->audit->log('role.delete', 'roles', $role->id, ['name' => $name]);

        return $this->data(['deleted' => true]);
    }
}
