<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

class FirewallRule extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'firewall_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'methods' => 'array',
            'paths' => 'array',
            'limit' => 'integer',
            'window_seconds' => 'integer',
            'priority' => 'integer',
            'enabled' => 'boolean',
            'hits' => 'integer',
        ];
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    /** "5 / minute" or "20 / 10 min", the way the rules table shows it. */
    public function getRateAttribute(): string
    {
        $w = $this->window_seconds;
        $per = match (true) {
            $w === 1 => 'second',
            $w === 60 => 'minute',
            $w === 3600 => 'hour',
            $w % 3600 === 0 => intdiv($w, 3600).' h',
            $w % 60 === 0 => intdiv($w, 60).' min',
            default => $w.' s',
        };

        return number_format($this->limit).' / '.$per;
    }
}
