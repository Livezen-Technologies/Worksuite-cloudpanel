<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** A WordPress install the toolkit manages (WP-001). */
class WpSite extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'plugins' => 'array', 'themes' => 'array', 'settings' => 'array', 'vulnerabilities' => 'array',
            'staging' => 'boolean', 'staging_password' => 'encrypted', 'inventoried_at' => 'datetime', 'scanned_at' => 'datetime',
        ];
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
