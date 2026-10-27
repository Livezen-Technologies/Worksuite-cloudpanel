<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'images';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'scan_result' => 'array',
            'scanned_at' => 'datetime',
            'docker_created_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }
}
