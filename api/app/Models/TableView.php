<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** TBL-4: a saved table view, personal or shared with the organisation. */
class TableView extends Model
{
    use BelongsToOrganization, Uuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['config' => 'array', 'shared' => 'boolean', 'is_default' => 'boolean'];
    }
}
