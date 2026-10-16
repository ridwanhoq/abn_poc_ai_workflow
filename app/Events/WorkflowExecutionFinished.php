<?php

namespace App\Events;

use App\Models\WorkflowExecution;
use Illuminate\Foundation\Events\Dispatchable;

class WorkflowExecutionFinished
{
    use Dispatchable;

    public function __construct(public readonly WorkflowExecution $execution) {}
}
