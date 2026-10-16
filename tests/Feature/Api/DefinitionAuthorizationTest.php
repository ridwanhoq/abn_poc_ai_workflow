<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use App\Services\ExecutionService;
use Database\Seeders\WorkflowTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a workflow may *do* is limited by the permissions of the person who saves it.
 */
class DefinitionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function usersQueryDefinition(): array
    {
        return [
            'nodes' => [
                $this->node('start', 'trigger.manual'),
                $this->node('find', 'data.find_records', ['model' => 'users', 'filters' => []]),
            ],
            'edges' => [$this->edge('start', 'find')],
        ];
    }

    #[Test]
    public function only_user_managers_can_save_workflows_that_read_the_user_directory(): void
    {
        $manager = $this->userWithRole(Role::WORKFLOW_MANAGER);
        $admin = $this->userWithRole(Role::ADMIN);
        $own = $this->workflowFor($manager, [$this->node('start', 'trigger.manual')], []);

        $this->actingAs($manager)->putJson("/api/v1/workflows/{$own->id}", ['definition' => $this->usersQueryDefinition()])
            ->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'users'));
        $this->actingAs($manager)->postJson('/api/v1/workflows', ['name' => 'Exfil', 'definition' => $this->usersQueryDefinition()])->assertForbidden();

        $this->actingAs($admin)->postJson('/api/v1/workflows', ['name' => 'Directory', 'definition' => $this->usersQueryDefinition()])->assertCreated();

        // The ERP reminder template reads users.verified_at, so importing it needs users.manage too.
        $this->seed(WorkflowTemplateSeeder::class);
        $template = WorkflowTemplate::query()->where('key', 'erp_unverified_user_reminder')->firstOrFail();
        $this->actingAs($manager)->postJson("/api/v1/templates/{$template->id}/import")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/templates/{$template->id}/import")->assertCreated();
    }

    #[Test]
    public function subworkflow_nodes_may_only_call_workflows_the_editor_may_run(): void
    {
        $manager = $this->userWithRole(Role::WORKFLOW_MANAGER);
        $admin = $this->userWithRole(Role::ADMIN);
        $adminsWorkflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);
        $managersWorkflow = $this->workflowFor($manager, [$this->node('start', 'trigger.manual')], []);
        $own = $this->workflowFor($manager, [$this->node('start', 'trigger.manual')], []);
        $definition = fn (int $childId) => [
            'nodes' => [$this->node('start', 'trigger.manual'), $this->node('call', 'utility.subworkflow', ['workflow_id' => $childId])],
            'edges' => [$this->edge('start', 'call')],
        ];

        $this->actingAs($manager)->putJson("/api/v1/workflows/{$own->id}", ['definition' => $definition($adminsWorkflow->id)])->assertForbidden();
        $this->actingAs($manager)->putJson("/api/v1/workflows/{$own->id}", ['definition' => $definition($managersWorkflow->id)])->assertOk();
    }

    #[Test]
    public function in_app_notifications_only_reach_members_of_the_workflows_workspace(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $colleague = $this->userWithRole(Role::VIEWER);
        $stranger = $this->userWithRole(Role::VIEWER, Workspace::query()->create(['name' => 'Other', 'slug' => 'other']));
        $notify = fn (User $to) => $this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('ping', 'notify.in_app', ['recipient' => $to->email, 'title' => 'Hi', 'message' => 'Hello']),
        ], [$this->edge('start', 'ping')]);

        $ok = app(ExecutionService::class)->start($notify($colleague), 'manual', [], $admin, 'test', null, [], true);
        $this->assertSame(ExecutionStatus::Success, $ok->status);
        $this->assertSame(1, $colleague->notifications()->count());

        $blocked = app(ExecutionService::class)->start($notify($stranger), 'manual', [], $admin, 'test', null, [], true);
        $this->assertSame(ExecutionStatus::Failed, $blocked->status);
        $this->assertSame(0, $stranger->notifications()->count());
        // Same message whether the address is unknown or outside the workspace (no account enumeration).
        $unknown = app(ExecutionService::class)->start($this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('ping', 'notify.in_app', ['recipient' => 'nobody@example.test', 'title' => 'Hi', 'message' => 'Hello']),
        ], [$this->edge('start', 'ping')]), 'manual', [], $admin, 'test', null, [], true);
        $this->assertSame($blocked->error_message, $unknown->error_message);
    }
}
