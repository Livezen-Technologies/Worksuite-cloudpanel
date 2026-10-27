<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Passkey extends Model
{
    use Uuid;

    protected $table = 'passkeys';

    protected $guarded = ['id'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return [

        ];
    }
}
