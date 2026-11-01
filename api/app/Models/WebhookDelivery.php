<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'webhook_deliveries';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;
}
