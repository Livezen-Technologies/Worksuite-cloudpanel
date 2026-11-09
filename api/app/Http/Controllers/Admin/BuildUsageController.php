<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BuildService;

/** BLD-3/9: this month's build minutes and layer cache hit rate. */
class BuildUsageController extends Controller
{
    protected ?string $kind = null;

    public function __invoke(BuildService $builds)
    {
        return ['data' => $builds->usage($this->kind) + ['limits' => $this->limits($builds)]];
    }

    protected function limits(BuildService $builds): array
    {
        return ['build_minutes' => 0, 'timeout_minutes' => (int) config('platform.builds.timeout_minutes', 60)];
    }
}
