<?php

use App\Models\User;
use App\Models\WorkflowExecution;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

// Live updates for one execution: same rule as viewing it through the API.
Broadcast::channel('executions.{executionId}', function (User $user, int $executionId) {
    $execution = WorkflowExecution::query()->find($executionId);

    return $execution !== null && $user->can('view', $execution);
});

// Workspace-wide execution activity (top-bar indicator, lists).
Broadcast::channel('workspaces.{workspaceId}.executions', function (User $user, int $workspaceId) {
    return $user->hasPermission('executions.view') && $user->belongsToWorkspace($workspaceId);
});
