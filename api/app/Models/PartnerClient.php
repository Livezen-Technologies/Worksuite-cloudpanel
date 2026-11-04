<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** A scoped server-to-server credential for a partner platform (WorkSuite Cloud). Not organisation-scoped. */
class PartnerClient extends Model
{
    use Uuid;

    protected $guarded = ['id'];

    protected $hidden = ['secret', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'scopes' => 'array',
            'allowed_ips' => 'array',
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true) || in_array('*', $this->scopes ?? [], true);
    }
}
