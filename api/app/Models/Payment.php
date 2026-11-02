<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money in. One row per attempt to collect, whether it came from a gateway or
 * was typed in by an administrator who banked a transfer.
 *
 * `refunded_cents` is the part of this payment already sent back. It is the
 * ceiling every refund is checked against, so a payment can never give back
 * more than it took.
 */
class Payment extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'payments';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        // The panel reads these across every customer, so the parent row's own
        // tenant scope must not hide a child that belongs to the same organisation.
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('organization');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class)->withoutGlobalScope('organization');
    }

    /** What is still refundable on this payment, never below zero. */
    public function refundableCents(): int
    {
        if (! in_array($this->status, ['succeeded', 'partially_refunded'], true)) {
            return 0;
        }

        return max(0, (int) $this->amount_cents - (int) $this->refunded_cents);
    }
}
