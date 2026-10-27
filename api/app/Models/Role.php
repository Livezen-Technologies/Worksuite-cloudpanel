<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use Uuid;

    protected $table = 'roles';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [

        ];
    }

    public function permissions() { return $this->belongsToMany(Permission::class, 'role_permissions')->withPivot('constraint', 'requires_approval'); }
}
