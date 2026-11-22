<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Database extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'databases';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'last_backup_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }
    public function secret() { return $this->belongsTo(Secret::class); }

    /** The container DatabaseService names for this database: db-<slug>. */
    public function containerName(): string
    {
        return 'db-'.\Illuminate\Support\Str::slug((string) $this->name);
    }

    /**
     * The database's container, repairing a missing or stale container_id.
     * Only ever looks on this database's own server: a same-named container
     * on another node (an orphan from an earlier attempt) is never picked.
     */
    public function resolveContainer(): ?Container
    {
        if ($this->container_id && ($c = Container::allTenants()->where('server_id', $this->server_id)->find($this->container_id))) {
            return $c;
        }
        if (! $this->server_id) {
            return null;
        }
        $c = Container::allTenants()->where('server_id', $this->server_id)->where('name', $this->containerName())
            ->orderByRaw('docker_id is null')->orderByDesc('updated_at')->first();
        if ($c && $c->id !== $this->container_id) {
            static::allTenants()->whereKey($this->id)->update(['container_id' => $c->id]);
            $this->container_id = $c->id;
            $this->syncOriginalAttribute('container_id');
        }

        return $c;
    }
}
