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
        ];
    }

    public function memberships() { return $this->hasMany(Membership::class); }
    public function subscription() { return $this->hasOne(Subscription::class)->orderByDesc('created_at'); }
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get(array_replace_recursive(config('platform.default_settings'), $this->settings ?? []), $key, $default);
    }
}
