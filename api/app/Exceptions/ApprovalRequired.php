<?php

namespace App\Exceptions;

use App\Models\Approval;
use RuntimeException;

/** Thrown when an action was parked for a second approver; rendered as 202. */
class ApprovalRequired extends RuntimeException
{
    public function __construct(public readonly Approval $approval)
    {
        parent::__construct('This action needs approval from a second person.');
    }
}
