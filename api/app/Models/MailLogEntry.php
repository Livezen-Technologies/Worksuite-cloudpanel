<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** MCL: one delivery attempt parsed from the Postfix log; scoped by organization_id in queries. */
class MailLogEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
