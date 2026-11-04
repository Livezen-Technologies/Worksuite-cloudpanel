<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for money back, and what was decided about it.
 *
 * The accounting record of a refund is still the credit note; this is the
 * decision around it, which is why a rejected request keeps its reason and
 * never produces one.
 */
class RefundRequest extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'refund_requests';

    protected $guarded = ['id'];

    protected $hidden = [];

    /** The states from which a request may still be acted on. */
    public const OPEN = ['pending', 'approved', 'processing'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        // The panel reads these across every customer, so the parent row's own
        // tenant scope must not hide a child that belongs to the same organisation.
        return $this->belongsTo(Payment::class)->withoutGlobalScope('organization');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('organization');
    }
}
