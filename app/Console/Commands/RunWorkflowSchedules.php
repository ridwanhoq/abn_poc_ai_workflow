<?php

namespace App\Console\Commands;

use App\Workflow\Triggers\TriggerDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:run-schedules')]
#[Description('Dispatch executions for every due workflow schedule (run every minute by the scheduler).')]
class RunWorkflowSchedules extends Command
{
    public function handle(TriggerDispatcher $dispatcher): int
    {
        $count = $dispatcher->runDueSchedules();
        $this->info("Dispatched {$count} scheduled execution(s).");

        return self::SUCCESS;
    }
}
