<?php

namespace App\Jobs;

use App\Services\DeploymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunDeploymentPipeline implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $deploymentId) {}

    public function handle(DeploymentService $deployments): void
    {
        $deployments->run($this->deploymentId);
    }
}
