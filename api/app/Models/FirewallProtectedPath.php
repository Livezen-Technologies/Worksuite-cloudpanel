<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class FirewallProtectedPath extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'firewall_protected_paths';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
