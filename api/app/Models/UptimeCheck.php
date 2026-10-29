<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class UptimeCheck extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'uptime_checks';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'checked_at' => 'datetime'];
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
