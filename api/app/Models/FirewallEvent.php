<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class FirewallEvent extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'firewall_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function rule()
    {
        return $this->belongsTo(FirewallRule::class, 'firewall_rule_id');
    }
}
