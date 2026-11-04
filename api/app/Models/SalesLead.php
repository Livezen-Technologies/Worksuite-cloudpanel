<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** SITE: a "Contact sales" request. Platform-wide (no tenant): it comes from a visitor. */
class SalesLead extends Model
{
    use Uuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
