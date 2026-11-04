<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** A partner's customer account, mapped to the panel organisation that holds its deployments. */
class PartnerAccount extends Model
{
    use Uuid;

    protected $guarded = ['id'];

    public function client() { return $this->belongsTo(PartnerClient::class, 'partner_client_id'); }
    public function serviceUser() { return $this->belongsTo(User::class, 'service_user_id'); }
}
