<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;

/**
 * Super Admins bypass these checks through Gate::before.
 * Others need the permission, workspace membership and — for changes to
 * someone else's workflow — the "workflows.manage_all" permission.
 */
class WorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('workflows.view');
    }

    public function view(User $user, Workflow $workflow): bool
    {
        return $user->hasPermission('workflows.view') && $user->belongsToWorkspace($workflow->workspace_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('workflows.create');
    }

    public function update(User $user, Workflow $workflow): bool
    {
        return $this->owns($user, $workflow, 'workflows.update');
    }

    public function delete(User $user, Workflow $workflow): bool
    {
        return $this->owns($user, $workflow, 'workflows.delete');
    }

    public function execute(User $user, Workflow $workflow): bool
    {
        return $this->owns($user, $workflow, 'workflows.execute');
    }

    public function activate(User $user, Workflow $workflow): bool
    {
        return $this->owns($user, $workflow, 'workflows.activate');
    }

    private function owns(User $user, Workflow $workflow, string $permission): bool
    {
        return $user->hasPermission($permission)
            && $user->belongsToWorkspace($workflow->workspace_id)
            && ($workflow->created_by === $user->id || $user->hasPermission('workflows.manage_all'));
    }
}
