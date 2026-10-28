<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'alerts';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function rule() { return $this->belongsTo(AlertRule::class, 'alert_rule_id'); }
}
