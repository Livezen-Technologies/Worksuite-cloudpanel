<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class FirewallCountry extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'firewall_countries';

    protected $guarded = ['id'];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
