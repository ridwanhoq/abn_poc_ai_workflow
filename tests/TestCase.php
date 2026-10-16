<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Page tests assert server behaviour, not compiled assets.
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Create a user with the given role in a (shared) test workspace.
     */
    protected function userWithRole(string $role = Role::SUPER_ADMIN, ?Workspace $workspace = null): User
    {
        if (Role::query()->doesntExist()) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        $workspace ??= Workspace::query()->firstOrCreate(['slug' => 'test'], ['name' => 'Test workspace']);

        $user = User::factory()->create();
        $user->forceFill([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'current_workspace_id' => $workspace->id,
        ])->save();
        $user->workspaces()->attach($workspace->id);

        return $user->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  array<string, mixed>  $attributes
     */
    protected function workflowFor(User $owner, array $nodes, array $edges, array $attributes = []): Workflow
    {
        return Workflow::factory()->withGraph($nodes, $edges)->create(array_merge([
            'created_by' => $owner->id,
            'workspace_id' => $owner->current_workspace_id,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function node(string $key, string $type, array $config = [], array $settings = []): array
    {
        return ['key' => $key, 'type' => $type, 'name' => $key, 'position' => ['x' => 0, 'y' => 0], 'config' => $config, 'settings' => $settings];
    }

    /**
     * @return array<string, string>
     */
    protected function edge(string $source, string $target, string $handle = 'main'): array
    {
        return ['id' => "{$source}-{$handle}-{$target}", 'source' => $source, 'target' => $target, 'sourceHandle' => $handle];
    }
}
