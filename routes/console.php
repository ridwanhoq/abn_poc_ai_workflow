<?php

use App\Models\WorkflowExecution;
use Illuminate\Support\Facades\Schedule;

// Dispatch due workflow schedules. withoutOverlapping + the conditional
// "claim" update in TriggerDispatcher prevent duplicate dispatches.
Schedule::command('workflows:run-schedules')->everyMinute()->withoutOverlapping();

// Execution retention (config workflows.retention_days).
Schedule::command('model:prune', ['--model' => [WorkflowExecution::class]])->daily();
