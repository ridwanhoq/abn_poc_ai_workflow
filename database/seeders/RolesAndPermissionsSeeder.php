<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = Permission::CATALOGUE;

    public const ROLES = [
        Role::SUPER_ADMIN => ['Super Admin', 'Unrestricted access to every feature.', '*'],
        Role::ADMIN => ['Admin', 'Manages workflows, executions, templates, integrations and users.', [
            'workflows.view', 'workflows.create', 'workflows.update', 'workflows.delete', 'workflows.execute', 'workflows.activate', 'workflows.manage_all',
            'executions.view', 'executions.manage', 'templates.view', 'templates.import', 'integrations.view', 'integrations.manage',
            'variables.view', 'variables.manage', 'playground.use', 'users.manage', 'workspaces.manage', 'audit.view',
        ]],
        Role::WORKFLOW_MANAGER => ['Workflow Manager', 'Creates, edits, activates and runs their own workflows.', [
            'workflows.view', 'workflows.create', 'workflows.update', 'workflows.delete', 'workflows.execute', 'workflows.activate',
            'executions.view', 'executions.manage', 'templates.view', 'templates.import', 'integrations.view', 'variables.view', 'playground.use',
        ]],
        Role::VIEWER => ['Viewer', 'Read-only access to workflows and execution history.', [
            'workflows.view', 'executions.view', 'templates.view', 'integrations.view', 'variables.view',
        ]],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => [$name, $group]) {
            Permission::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group]);
        }

        $all = Permission::query()->pluck('id', 'slug');

        foreach (self::ROLES as $slug => [$name, $description, $permissions]) {
            $role = Role::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description]);
            $role->permissions()->sync($permissions === '*' ? $all->values() : $all->only($permissions)->values());
        }
    }
}
