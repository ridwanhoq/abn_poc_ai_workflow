<?php

use App\Http\Controllers\Api\V1;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : Inertia::render('Welcome'))->name('home');

/*
|--------------------------------------------------------------------------
| Application pages (Inertia shells — data is loaded from /api/v1 via Axios)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Overview')->name('dashboard');
    Route::inertia('workflows', 'workflows/Index')->middleware('can:workflows.view')->name('workflows.index');
    Route::get('workflows/{workflow}/edit', function (Workflow $workflow) {
        Gate::authorize('view', $workflow);

        return Inertia::render('workflows/Editor', ['workflowId' => $workflow->id]);
    })->name('workflows.edit');
    Route::inertia('templates', 'Templates')->middleware('can:templates.view')->name('templates.index');
    Route::inertia('executions', 'executions/Index')->middleware('can:executions.view')->name('executions.index');
    Route::get('executions/{execution}', function (WorkflowExecution $execution) {
        Gate::authorize('view', $execution);

        return Inertia::render('executions/Show', ['executionId' => $execution->id]);
    })->name('executions.show');
    Route::inertia('integrations', 'Integrations')->middleware('can:integrations.view')->name('integrations.index');
    Route::inertia('playground', 'Playground')->middleware('can:playground.use')->name('playground');
    Route::inertia('data', 'DataVariables')->middleware('can:variables.view')->name('data.index');
    Route::inertia('settings/users', 'settings/Users')->middleware('can:users.manage')->name('settings.users');
    Route::inertia('settings/workspaces', 'settings/Workspaces')->middleware('can:workspaces.manage')->name('settings.workspaces');
    Route::inertia('settings/audit', 'settings/Audit')->middleware('can:audit.view')->name('settings.audit');
});

/*
|--------------------------------------------------------------------------
| REST API v1 (session-authenticated, CSRF-protected via XSRF-TOKEN cookie)
|--------------------------------------------------------------------------
*/
Route::prefix('api/v1')->middleware(['auth'])->name('api.')->group(function () {
    Route::get('dashboard', V1\DashboardController::class)->name('dashboard');
    Route::get('search', V1\SearchController::class)->name('search');
    Route::get('node-types', V1\NodeTypeController::class)->name('node-types');

    Route::get('workflows', [V1\WorkflowController::class, 'index'])->name('workflows.index');
    Route::post('workflows', [V1\WorkflowController::class, 'store'])->name('workflows.store');
    Route::get('workflows/{workflow}', [V1\WorkflowController::class, 'show'])->name('workflows.show');
    Route::put('workflows/{workflow}', [V1\WorkflowController::class, 'update'])->name('workflows.update');
    Route::delete('workflows/{workflow}', [V1\WorkflowController::class, 'destroy'])->name('workflows.destroy');
    Route::post('workflows/{workflow}/validate', [V1\WorkflowController::class, 'validateDefinition'])->name('workflows.validate');
    Route::post('workflows/{workflow}/activate', [V1\WorkflowController::class, 'activate'])->name('workflows.activate');
    Route::post('workflows/{workflow}/deactivate', [V1\WorkflowController::class, 'deactivate'])->name('workflows.deactivate');
    Route::post('workflows/{workflow}/duplicate', [V1\WorkflowController::class, 'duplicate'])->name('workflows.duplicate');
    Route::post('workflows/{workflow}/execute', [V1\WorkflowController::class, 'execute'])->middleware('throttle:workflow-runs')->name('workflows.execute');
    Route::get('workflows/{workflow}/executions', [V1\WorkflowController::class, 'executions'])->name('workflows.executions');
    Route::get('workflows/{workflow}/versions', [V1\WorkflowController::class, 'versions'])->name('workflows.versions');
    Route::post('workflows/{workflow}/versions/{version}/restore', [V1\WorkflowController::class, 'restoreVersion'])->name('workflows.versions.restore');
    Route::post('workflows/{workflow}/webhook/rotate', [V1\WorkflowController::class, 'rotateWebhook'])->name('workflows.webhook.rotate');

    Route::get('executions', [V1\ExecutionController::class, 'index'])->name('executions.index');
    Route::get('executions/status', [V1\ExecutionController::class, 'status'])->name('executions.status');
    Route::get('executions/{execution}', [V1\ExecutionController::class, 'show'])->name('executions.show');
    Route::post('executions/{execution}/cancel', [V1\ExecutionController::class, 'cancel'])->name('executions.cancel');
    Route::post('executions/{execution}/retry', [V1\ExecutionController::class, 'retry'])->name('executions.retry');

    Route::get('templates', [V1\TemplateController::class, 'index'])->name('templates.index');
    Route::get('templates/{template}', [V1\TemplateController::class, 'show'])->name('templates.show');
    Route::post('templates/{template}/import', [V1\TemplateController::class, 'import'])->name('templates.import');

    Route::get('integrations', [V1\IntegrationController::class, 'index'])->name('integrations.index');
    Route::put('integrations/{integration}', [V1\IntegrationController::class, 'update'])->name('integrations.update');
    Route::post('integrations/{integration}/test', [V1\IntegrationController::class, 'test'])->name('integrations.test');

    Route::get('variables', [V1\VariableController::class, 'index'])->name('variables.index');
    Route::post('variables', [V1\VariableController::class, 'store'])->name('variables.store');
    Route::put('variables/{variable}', [V1\VariableController::class, 'update'])->name('variables.update');
    Route::delete('variables/{variable}', [V1\VariableController::class, 'destroy'])->name('variables.destroy');

    Route::get('data', [V1\DataController::class, 'index'])->name('data.index');
    Route::get('data/{alias}', [V1\DataController::class, 'show'])->name('data.show');
    Route::get('media-assets', [V1\MediaAssetController::class, 'index'])->name('media-assets.index');
    Route::post('media-assets', [V1\MediaAssetController::class, 'store'])->middleware('throttle:10,1')->name('media-assets.store');

    Route::get('demo-events', [V1\DemoEventController::class, 'options'])->name('demo-events.options');
    Route::post('demo-events/publish-content', [V1\DemoEventController::class, 'publishContent'])->middleware('throttle:30,1')->name('demo-events.publish-content');
    Route::post('demo-events/employees', [V1\DemoEventController::class, 'createEmployee'])->middleware('throttle:30,1')->name('demo-events.employees');
    Route::post('demo-events/leave-requests', [V1\DemoEventController::class, 'createLeaveRequest'])->middleware('throttle:30,1')->name('demo-events.leave-requests');

    Route::post('playground', V1\PlaygroundController::class)->middleware('throttle:60,1')->name('playground');

    Route::get('notifications', [V1\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [V1\NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [V1\NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('workspaces', [V1\WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::put('workspaces/current', [V1\WorkspaceController::class, 'switch'])->name('workspaces.switch');
    Route::get('workspaces/manage', [V1\WorkspaceController::class, 'manage'])->name('workspaces.manage');
    Route::post('workspaces', [V1\WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::put('workspaces/{workspace}', [V1\WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::delete('workspaces/{workspace}', [V1\WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
    Route::put('workspaces/{workspace}/members', [V1\WorkspaceController::class, 'members'])->name('workspaces.members');

    Route::get('users', [V1\UserController::class, 'index'])->name('users.index');
    Route::put('users/{user}', [V1\UserController::class, 'update'])->name('users.update');
    Route::get('audit-logs', V1\AuditLogController::class)->name('audit-logs');
});

require __DIR__.'/settings.php';
