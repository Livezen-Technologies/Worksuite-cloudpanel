<?php

namespace App\Support;

use App\Models\Role;

/** Resolves role -> permission grants from the seeded roles tables (memoised per request). */
class Rbac
{
    private ?array $matrix = null;

    public function matrix(): array
    {
        if ($this->matrix === null) {
            $this->matrix = [];
            foreach (Role::with('permissions')->get() as $role) {
                foreach ($role->permissions as $permission) {
                    $this->matrix[$role->name][$permission->name] = [
                        'constraint' => $permission->pivot->constraint,
                        'requires_approval' => (bool) $permission->pivot->requires_approval,
                    ];
                }
            }
        }

        return $this->matrix;
    }

    public function grant(?string $role, string $permission): ?array
    {
        return $role ? ($this->matrix()[$role][$permission] ?? null) : null;
    }

    public function permissionsFor(?string $role): array
    {
        return $role ? array_keys($this->matrix()[$role] ?? []) : [];
    }

    public function flush(): void
    {
        $this->matrix = null;
    }

    /** Parse a config grant like "all+approval". */
    public static function parse(string $grant): array
    {
        [$constraint, $flag] = array_pad(explode('+', $grant, 2), 2, null);

        return ['constraint' => $constraint, 'requires_approval' => $flag === 'approval'];
    }
}
