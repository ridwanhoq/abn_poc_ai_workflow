<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admins_create_rename_and_staff_workspaces(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $manager = $this->userWithRole(Role::WORKFLOW_MANAGER);

        $id = $this->actingAs($admin)->postJson('/api/v1/workspaces', ['name' => 'Kids Content', 'color' => '#00c875'])
            ->assertCreated()->json('id');
        $this->assertTrue($admin->belongsToWorkspace($id), 'Creator becomes a member');
        $this->assertSame('kids-content', Workspace::query()->find($id)?->slug);

        $this->actingAs($admin)->putJson("/api/v1/workspaces/{$id}", ['name' => 'Kids & Family', 'color' => '#a25ddc'])->assertOk();
        $this->assertDatabaseHas('workspaces', ['id' => $id, 'name' => 'Kids & Family']);

        $this->actingAs($admin)->putJson("/api/v1/workspaces/{$id}/members", ['user_ids' => [$manager->id]])->assertOk();
        $this->assertTrue($manager->fresh()->belongsToWorkspace($id));
        $this->assertTrue($admin->fresh()->belongsToWorkspace($id), 'Admins cannot remove themselves');

        $this->actingAs($admin)->getJson('/api/v1/workspaces/manage')->assertOk()
            ->assertJsonFragment(['name' => 'Kids & Family', 'members_count' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'workspace.members_changed', 'subject_id' => $id]);
    }

    #[Test]
    public function removed_members_lose_their_current_workspace(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $viewer = $this->userWithRole(Role::VIEWER);
        $workspaceId = $viewer->current_workspace_id;

        $this->actingAs($admin)->putJson("/api/v1/workspaces/{$workspaceId}/members", ['user_ids' => [$admin->id]])->assertOk();

        $this->assertNull($viewer->fresh()->current_workspace_id);
        $this->assertFalse($viewer->fresh()->belongsToWorkspace($workspaceId));
    }

    #[Test]
    public function workspaces_with_workflows_cannot_be_deleted(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workspaceId = $admin->current_workspace_id;
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);

        $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$workspaceId}")->assertStatus(422);

        $workflow->forceDelete();
        $this->actingAs($admin)->deleteJson("/api/v1/workspaces/{$workspaceId}")->assertOk();
        $this->assertDatabaseMissing('workspaces', ['id' => $workspaceId]);
        $this->assertNull($admin->fresh()->current_workspace_id);
    }

    #[Test]
    public function management_requires_permission_and_membership(): void
    {
        $manager = $this->userWithRole(Role::WORKFLOW_MANAGER);
        $outsiderAdmin = $this->userWithRole(Role::ADMIN, Workspace::query()->create(['name' => 'Other', 'slug' => 'other']));
        $target = $manager->current_workspace_id;

        $this->actingAs($manager)->postJson('/api/v1/workspaces', ['name' => 'Nope', 'color' => '#6161ff'])->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/workspaces/manage')->assertForbidden();
        $this->actingAs($outsiderAdmin)->putJson("/api/v1/workspaces/{$target}", ['name' => 'Hijack', 'color' => '#6161ff'])->assertForbidden();
        $this->actingAs($outsiderAdmin)->getJson('/api/v1/workspaces/manage')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($outsiderAdmin)->postJson('/api/v1/workspaces', ['name' => 'Bad color', 'color' => 'red'])->assertStatus(422);
        $this->actingAs($manager)->get('/settings/workspaces')->assertForbidden();
        $this->assertSame(0, Workflow::query()->count());
    }
}
