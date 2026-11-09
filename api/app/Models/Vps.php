<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** UVPS: one customer virtual server. */
class Vps extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'vps';

    protected $guarded = ['id'];

    public function server() { return $this->belongsTo(Server::class); }

    public function container() { return $this->belongsTo(Container::class); }

    /** The Docker name on the host: unique across tenants. */
    public function dockerName(): string
    {
        return 'vps-'.substr(str_replace('-', '', $this->id), 0, 12);
    }
}
