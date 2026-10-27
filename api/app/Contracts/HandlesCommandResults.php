<?php

namespace App\Contracts;

use App\Models\AgentCommand;
use App\Models\Job;

/** A service that continues a job when one of its agent commands finishes. */
interface HandlesCommandResults
{
    public function onCommandFinished(AgentCommand $command, Job $job): void;
}
