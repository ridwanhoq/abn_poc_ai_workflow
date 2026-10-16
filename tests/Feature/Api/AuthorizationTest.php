<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\WorkflowExecution;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_receive_401_from_the_api(): void
    {
        $this->getJson('/api/v1/workflows')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    #[Test]
    public function viewers_can_read_but_not_modify_or_execute(): void
    {
        Queue::fake();
        $admin = $this->userWithRole(Role::ADMIN);
        $viewer = $this->userWithRole(Role::VIEWER);
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);

        $this->actingAs($viewer)->getJson("/api/v1/workflows/{$workflow->id}")->assertOk()->assertJsonPath('data.can.update', false);
        $this->actingAs($viewer)->postJson('/api/v1/workflows', ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/v1/workflows/{$workflow->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/workflows/{$workflow->id}/execute")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/workflows/{$workflow->id}/activate")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/workflows/{$workflow->id}")->assertForbidden();
        $template = WorkflowTemplate::query()->create(['key' => 't', 'name' => 'T', 'category' => 'Test', 'definition' => $workflow->definition]);
        $this->actingAs($viewer)->postJson("/api/v1/templates/{$template->id}/import")->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/users')->assertForbidden();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function managers_cannot_change_workflows_created_by_others(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $manager = $this->userWithRole(Role::WORKFLOW_MANAGER);
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);

        $this->actingAs($manager)->getJson("/api/v1/workflows/{$workflow->id}")->assertOk();
        $this->actingAs($manager)->putJson("/api/v1/workflows/{$workflow->id}", ['name' => 'hijack'])->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/workflows/{$workflow->id}/execute")->assertForbidden();

        // Admins hold workflows.manage_all.
        $own = $this->workflowFor($manager, [$this->node('start', 'trigger.manual')], []);
        $this->actingAs($admin)->putJson("/api/v1/workflows/{$own->id}", ['name' => 'Reviewed'])->assertOk();
    }

    #[Test]
    public function users_cannot_access_other_workspaces_or_their_executions(): void
    {
        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other']);
        $outsider = $this->userWithRole(Role::ADMIN, $other);
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);
        $execution = WorkflowExecution::create(['workflow_id' => $workflow->id, 'trigger_type' => 'manual', 'status' => 'success', 'mode' => 'test']);

        $this->actingAs($outsider)->getJson("/api/v1/workflows/{$workflow->id}")->assertForbidden();
        $this->actingAs($outsider)->getJson("/api/v1/executions/{$execution->id}")->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/v1/workflows')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($outsider)->getJson('/api/v1/executions')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function super_admins_have_unrestricted_access(): void
    {
        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other']);
        $super = $this->userWithRole(Role::SUPER_ADMIN, $other);
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);

        $this->actingAs($super)->getJson("/api/v1/workflows/{$workflow->id}")->assertOk()->assertJsonPath('data.can.update', true);
        $this->actingAs($super)->getJson('/api/v1/users')->assertOk();
        $this->actingAs($super)->getJson('/api/v1/audit-logs')->assertOk();
    }

    #[Test]
    public function admins_cannot_grant_super_admin(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $viewer = $this->userWithRole(Role::VIEWER);
        $superRole = Role::query()->where('slug', Role::SUPER_ADMIN)->value('id');

        $this->actingAs($admin)->putJson("/api/v1/users/{$viewer->id}", ['role_id' => $superRole])->assertForbidden();
        $this->actingAs($admin)->putJson("/api/v1/users/{$viewer->id}", ['role_id' => Role::query()->where('slug', Role::WORKFLOW_MANAGER)->value('id')])->assertOk();
    }

    #[Test]
    public function protected_pages_require_permissions(): void
    {
        $viewer = $this->userWithRole(Role::VIEWER);

        $this->actingAs($viewer)->get('/workflows')->assertOk();
        $this->actingAs($viewer)->get('/settings/users')->assertForbidden();
        $this->actingAs($viewer)->get('/playground')->assertForbidden();
    }
}
