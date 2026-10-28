<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Release extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'releases';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public const UPDATED_AT = null;
    public function application() { return $this->belongsTo(Application::class); }
}
