<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use Uuid;

    protected $table = 'organizations';

    protected $guarded = ['id'];

    protected $hidden = ['last_audit_hash', 'payment_customer_id'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'payment_method' => 'array',
            'registered_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    // The panel reads these across every customer, so the reader's own tenant
    // scope must not hide a child that belongs to the account being looked at.
    // Each relation is already keyed by organization_id, so dropping the scope
    // cannot widen what it returns.
    public function memberships() { return $this->hasMany(Membership::class)->withoutGlobalScope('organization'); }
    public function subscription() { return $this->hasOne(Subscription::class)->withoutGlobalScope('organization')->orderByDesc('created_at'); }
    public function contacts() { return $this->hasMany(Contact::class)->withoutGlobalScope('organization'); }
    public function billingProfile() { return $this->hasOne(BillingProfile::class)->withoutGlobalScope('organization'); }
    public function groupMemberships() { return $this->hasMany(CustomerGroupMember::class)->withoutGlobalScope('organization'); }
    public function accountManager() { return $this->belongsTo(User::class, 'account_manager_id'); }
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get(array_replace_recursive(config('platform.default_settings'), $this->settings ?? []), $key, $default);
    }
}
