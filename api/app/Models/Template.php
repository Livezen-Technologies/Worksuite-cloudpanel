<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** TPL-1: one catalogue entry; global, not tenant-scoped. */
class Template extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'version' => 'integer', 'synced_at' => 'datetime'];
    }
}
