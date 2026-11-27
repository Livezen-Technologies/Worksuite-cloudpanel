<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operator configuration, not customer data, so it is platform-wide and holds
 * no organisation column; membership is the tenant-stamped join.
 */
class CustomerGroup extends Model
{
    use Uuid;

    protected $table = 'customer_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'plan_overrides' => 'array',
            'is_system' => 'boolean',
            'rules_run_at' => 'datetime',
            'rules_matched' => 'integer',
            'discount_percent' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(CustomerGroupMember::class)->withoutGlobalScope('organization');
    }
}
