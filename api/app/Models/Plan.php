<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use Uuid;

    protected $table = 'plans';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'vcpu' => 'float',
        ];
    }
}
