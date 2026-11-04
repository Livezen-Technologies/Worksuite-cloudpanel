<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Customer\CronController as CustomerCron;
use App\Models\Application;
use App\Models\ScheduledJob;
use App\Services\ScheduledJobService;
use Illuminate\Database\Eloquent\Builder;

/**
 * CRON-9: Operations → Cron & scheduler. Platform admins see and pause every customer's
 * jobs and may run commands on a server's own shell; plan limits do not apply here.
 * Organisation admins see their own organisation's jobs, as customers do.
 */
class CronController extends CustomerCron
{
    private function platform(): bool
    {
        return $this->seesAllTenants(request());
    }

    protected function modes(): array
    {
        return $this->platform() ? ScheduledJobService::MODES : ['container', 'oneoff', 'http'];
    }

    protected function query(): Builder
    {
        $q = $this->platform() ? ScheduledJob::allTenants() : ScheduledJob::query();

        return $q->whereIn('action', ScheduledJobService::ENGINE);
    }

    protected function apps(): Builder
    {
        return $this->platform() ? Application::allTenants() : Application::query();
    }

    protected function enforceLimits(): bool
    {
        return ! $this->platform();
    }

    protected function present(ScheduledJob $j, $apps = null): array
    {
        return parent::present($j, $apps) + ['organization_id' => $j->organization_id];
    }
}
