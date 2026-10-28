<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Registry extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'registries';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted',
        ];
    }

    protected $appends = ['has_credentials'];
    public function getHasCredentialsAttribute(): bool { return ! empty($this->attributes['credentials'] ?? null); }
}
