<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'usage_records';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'period' => 'date',
        ];
    }
}
