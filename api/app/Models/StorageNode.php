<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class StorageNode extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'storage_nodes';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [

        ];
    }
}
