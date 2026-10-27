<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Deployment extends Model
{
    use Uuid, BelongsToOrganization;

    protected $table = 'deployments';

    protected $guarded = ['id'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [
            'logs_tail' => 'array',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function application() { return $this->belongsTo(Application::class); }
    public function job() { return $this->belongsTo(Job::class); }
    public function user() { return $this->belongsTo(User::class); }
}
