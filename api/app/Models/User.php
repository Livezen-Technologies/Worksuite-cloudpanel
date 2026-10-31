<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, Uuid;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'password' => 'hashed',
            'email_preferences' => 'array',
            'mfa_secret' => 'encrypted',
            'mfa_enabled' => 'boolean',
            'mfa_recovery_codes' => 'array',
        ];
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope('organization');
    }

    public function currentOrganization()
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function membership(?string $organizationId = null): ?Membership
    {
        $organizationId ??= $this->current_organization_id;

        return Membership::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('user_id', $this->id)
            ->where('status', 'active')
            ->first();
    }

    public function roleName(): ?string
    {
        return $this->membership()?->role;
    }

    /** Permission grant for the user's role in the current org, or null. */
    public function grant(string $permission): ?array
    {
        return app(Rbac::class)->grant($this->roleName(), $permission);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->grant($permission) !== null;
    }

    /** MFA-POL: a second factor is set up when the person has an authenticator app or at least one passkey. */
    public function hasSecondFactor(): bool
    {
        return (bool) $this->mfa_enabled || \App\Models\Passkey::where('user_id', $this->id)->exists();
    }
}
