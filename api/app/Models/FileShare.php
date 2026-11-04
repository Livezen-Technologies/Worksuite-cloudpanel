<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** An expiring public link to a snapshot of files (FM File Sharing). */
class FileShare extends Model
{
    use Uuid, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'password_hash'];

    protected function casts(): array
    {
        return [
            'paths' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_downloaded_at' => 'datetime',
            'size' => 'integer', 'max_downloads' => 'integer', 'download_count' => 'integer',
        ];
    }

    /** Why the link no longer works, or null while it does. */
    public function unavailableReason(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            $this->max_downloads !== null && $this->download_count >= $this->max_downloads => 'used up',
            $this->status !== 'ready' => $this->status,
            default => null,
        };
    }

    public function storagePath(): string
    {
        return 'file-shares/'.$this->id.'/file';
    }
}
