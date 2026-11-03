<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account's place in one group. */
class CustomerGroupMember extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'customer_group_members';

    protected $guarded = ['id'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
