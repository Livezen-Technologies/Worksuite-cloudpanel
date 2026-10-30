<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** TPL-5: an app or stack installed from a template, with the values it was rendered with. */
class TemplateInstall extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['values'];

    protected function casts(): array
    {
        return ['values' => 'encrypted:array', 'version' => 'integer'];
    }
}
