<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** Who an invoice is addressed to, and the tax identity it is issued under. */
class BillingProfile extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'billing_profiles';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'tax_exempt' => 'boolean',
        ];
    }

    /** The address as it belongs on an invoice, blank lines dropped. */
    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->company_name,
            $this->contact_name,
            $this->address_line1,
            $this->address_line2,
            trim(implode(' ', array_filter([$this->postal_code, $this->city]))),
            $this->region,
            $this->country,
        ], fn ($l) => filled($l)));
    }
}
