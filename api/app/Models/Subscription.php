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
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'pending_plan_at' => 'datetime',
            'auto_renew' => 'boolean',
            'discount_percent' => 'integer',
        ];
    }

    public function plan() { return $this->belongsTo(Plan::class); }

    public function pendingPlan() { return $this->belongsTo(Plan::class, 'pending_plan_id'); }

    public function payments() { return $this->hasMany(Payment::class)->withoutGlobalScope('organization'); }

    /** A trial is only a trial while its end is still ahead. */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }
}
