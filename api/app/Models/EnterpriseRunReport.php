<?php

namespace App\Models;

use App\Models\Concerns\Uuid;
use Illuminate\Database\Eloquent\Model;

/** One enterprise run that is summarised in a single email (EnterpriseRunReportService). Never tenant-scoped. */
class EnterpriseRunReport extends Model
{
    use Uuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['job_ids' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'emailed_at' => 'datetime'];
    }
}
