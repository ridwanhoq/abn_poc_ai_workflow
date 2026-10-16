<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowExecution;

class WorkflowExecutionPolicy
{
    public function view(User $user, WorkflowExecution $execution): bool
    {
        return $user->hasPermission('executions.view')
            && $user->belongsToWorkspace($execution->workflow->workspace_id);
    }

    public function cancel(User $user, WorkflowExecution $execution): bool
    {
        return $this->manage($user, $execution);
    }

    public function retry(User $user, WorkflowExecution $execution): bool
    {
        return $this->manage($user, $execution);
    }

    private function manage(User $user, WorkflowExecution $execution): bool
    {
        return $user->hasPermission('executions.manage')
            && (new WorkflowPolicy)->execute($user, $execution->workflow);
    }
}
