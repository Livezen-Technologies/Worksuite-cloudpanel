<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person attached to a customer account, with or without a login. */
class Contact extends Model
{
    use Uuid, BelongsToOrganization;

    public const ROLES = ['owner', 'admin', 'billing', 'technical', 'abuse'];

    protected $table = 'contacts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
