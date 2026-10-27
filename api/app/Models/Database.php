<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Database extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'databases';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'last_backup_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }
    public function secret() { return $this->belongsTo(Secret::class); }
}
