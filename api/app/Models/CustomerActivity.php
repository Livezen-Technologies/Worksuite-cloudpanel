<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer's readable timeline. Not `audit_logs`: that chain is
 * tamper-evident and records staff actions for compliance, where this records
 * what happened to the account and may be pruned.
 */
class CustomerActivity extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'customer_activities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'occurred_at' => 'datetime'];
    }
}
