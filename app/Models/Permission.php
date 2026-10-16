<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /**
     * Every permission slug => [label, group]. Seeded by RolesAndPermissionsSeeder
     * and registered as a Gate ability by WorkflowServiceProvider.
     */
    public const CATALOGUE = [
        'workflows.view' => ['View workflows', 'workflows'],
        'workflows.create' => ['Create workflows', 'workflows'],
        'workflows.update' => ['Edit workflows', 'workflows'],
        'workflows.delete' => ['Delete workflows', 'workflows'],
        'workflows.execute' => ['Run workflows', 'workflows'],
        'workflows.activate' => ['Activate / deactivate workflows', 'workflows'],
        'workflows.manage_all' => ['Manage workflows created by others', 'workflows'],
        'executions.view' => ['View execution history', 'executions'],
        'executions.manage' => ['Cancel and retry executions', 'executions'],
        'templates.view' => ['View templates', 'templates'],
        'templates.import' => ['Import templates', 'templates'],
        'integrations.view' => ['View integrations', 'integrations'],
        'integrations.manage' => ['Configure integrations', 'integrations'],
        'variables.view' => ['View variables', 'data'],
        'variables.manage' => ['Manage variables', 'data'],
        'playground.use' => ['Use the AI playground', 'ai'],
        'users.manage' => ['Manage users and roles', 'admin'],
        'workspaces.manage' => ['Create and manage workspaces', 'admin'],
        'audit.view' => ['View audit log', 'admin'],
    ];

    protected $fillable = ['slug', 'name', 'group'];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
