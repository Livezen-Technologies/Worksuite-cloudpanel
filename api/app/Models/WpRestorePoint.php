<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** A WordPress restore point (WP-011): the dump lives at wp-content/ws-restore/{id}.sql. */
class WpRestorePoint extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['plugins' => 'array', 'themes' => 'array', 'restored_at' => 'datetime'];
    }

    public function dump(): string
    {
        return 'wp-content/ws-restore/'.$this->id.'.sql';
    }
}
