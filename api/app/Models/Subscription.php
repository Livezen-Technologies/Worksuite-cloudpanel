<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'subscriptions';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
        ];
    }

    public function plan() { return $this->belongsTo(Plan::class); }
}
