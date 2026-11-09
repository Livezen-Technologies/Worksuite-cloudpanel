<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Admin\BuildUsageController as AdminUsage;
use App\Models\Application;
use App\Services\BuildService;

/** Build minutes used against the package (BLD-9). */
class BuildUsageController extends AdminUsage
{
    protected ?string $kind = 'customer';

    protected function limits(BuildService $builds): array
    {
        return $builds->limits(new Application(['kind' => 'customer']));
    }
}
