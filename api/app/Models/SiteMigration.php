<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** An account being moved from another control panel (MIG-001). */
class SiteMigration extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['archive_url'];

    protected function casts(): array
    {
        return ['archive_url' => 'encrypted', 'inventory' => 'array', 'sites' => 'array', 'timeline' => 'array'];
    }

    public function event(string $message): void
    {
        $this->timeline = array_merge($this->timeline ?? [], [['at' => now()->toIso8601ZuluString(), 'event' => $message]]);
        $this->save();
    }
}
