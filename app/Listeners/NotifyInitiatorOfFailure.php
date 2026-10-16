<?php

namespace App\Listeners;

use App\Enums\ExecutionStatus;
use App\Events\WorkflowExecutionFinished;
use App\Models\User;
use App\Notifications\WorkflowNotification;

/**
 * Sends an in-app notification to the person who started an execution when it fails.
 */
class NotifyInitiatorOfFailure
{
    public function handle(WorkflowExecutionFinished $event): void
    {
        $execution = $event->execution;

        if ($execution->status !== ExecutionStatus::Failed || ! $execution->initiated_by || $execution->parent_execution_id) {
            return;
        }

        User::query()->find($execution->initiated_by)?->notify(new WorkflowNotification(
            'Workflow failed',
            "\"{$execution->workflow->name}\" failed: ".mb_substr((string) $execution->error_message, 0, 160),
            'error',
            ['workflow_id' => $execution->workflow_id, 'execution_id' => $execution->id],
        ));
    }
}
