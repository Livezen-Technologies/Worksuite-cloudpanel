<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide changes to one transactional email (EML-3). */
class EmailTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['overrides' => 'array', 'enabled' => 'boolean'];
    }
}
