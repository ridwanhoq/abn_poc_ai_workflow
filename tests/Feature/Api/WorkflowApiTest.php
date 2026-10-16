<?php

namespace Tests\Feature\Api;

use App\Jobs\RunWorkflowJob;
use App\Models\Role;
use App\Workflow\Engine\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    private function validDefinition(): array
    {
        return [
            'nodes' => [
                $this->node('start', 'trigger.manual'),
                $this->node('log', 'notify.log', ['message' => 'Hi {{ trigger.name }}']),
            ],
            'edges' => [$this->edge('start', 'log')],
        ];
    }

    #[Test]
    public function managers_can_create_update_list_and_delete_workflows(): void
    {
        $manager = $this->userWithRole(Role::WORKFLOW_MANAGER);

        $id = $this->actingAs($manager)->postJson('/api/v1/workflows', ['name' => 'My flow'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.trigger_type', 'manual')
            ->json('data.id');

        $this->actingAs($manager)->putJson("/api/v1/workflows/{$id}", ['definition' => $this->validDefinition(), 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('validation.valid', true);

        $this->assertDatabaseCount('workflow_versions', 2);

        $this->actingAs($manager)->getJson('/api/v1/workflows?q=Renamed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can.update', true);

        $this->actingAs($manager)->deleteJson("/api/v1/workflows/{$id}")->assertOk();
        $this->assertSoftDeleted('workflows', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.deleted', 'subject_id' => $id]);
    }

    #[Test]
    public function invalid_workflows_cannot_be_activated(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('log', 'notify.log', ['message' => ''])], []);

        $this->actingAs($admin)->postJson("/api/v1/workflows/{$workflow->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('validation.valid', false);

        $this->assertSame('draft', $workflow->fresh()->status->value);
    }

    #[Test]
    public function valid_workflows_activate_and_deactivate(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, $this->validDefinition()['nodes'], $this->validDefinition()['edges']);

        $this->actingAs($admin)->postJson("/api/v1/workflows/{$workflow->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($admin)->postJson("/api/v1/workflows/{$workflow->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    #[Test]
    public function an_active_workflow_cannot_be_saved_with_an_invalid_definition(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, $this->validDefinition()['nodes'], $this->validDefinition()['edges'], ['status' => 'active']);

        $this->actingAs($admin)->putJson("/api/v1/workflows/{$workflow->id}", ['definition' => ['nodes' => [], 'edges' => []]])
            ->assertStatus(422);
    }

    #[Test]
    public function manual_runs_are_queued_and_history_is_available(): void
    {
        Queue::fake();
        $manager = $this->userWithRole(Role::WORKFLOW_MANAGER);
        $workflow = $this->workflowFor($manager, $this->validDefinition()['nodes'], $this->validDefinition()['edges']);

        $id = $this->actingAs($manager)->postJson("/api/v1/workflows/{$workflow->id}/execute", ['payload' => ['name' => 'Ada']])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.mode', 'test')
            ->json('data.id');

        Queue::assertPushed(RunWorkflowJob::class);
        (new RunWorkflowJob($id))->handle(app(WorkflowEngine::class));

        $this->actingAs($manager)->getJson("/api/v1/executions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonCount(2, 'steps')
            ->assertJsonPath('steps.1.output.line', "[Workflow: {$workflow->name}] Hi Ada");

        $this->actingAs($manager)->getJson("/api/v1/workflows/{$workflow->id}/executions")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($manager)->getJson('/api/v1/executions?status=success')->assertOk()->assertJsonCount(1, 'data');
        $dashboard = $this->actingAs($manager)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('totals.successful', 1);
        $this->assertSame(1, collect($dashboard->json('activity'))->sum('success'));
        $this->assertSame(0, collect($dashboard->json('activity'))->sum('other'));
    }

    #[Test]
    public function failed_executions_expose_errors_and_can_be_retried(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('load', 'data.get_record', ['model' => 'employees', 'id' => 12345]),
        ], [$this->edge('start', 'load')]);

        $id = $this->actingAs($admin)->postJson("/api/v1/workflows/{$workflow->id}/execute")->json('data.id');

        $this->actingAs($admin)->getJson("/api/v1/executions/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.can.retry', true)
            ->assertJsonPath('steps.1.status', 'failed')
            ->assertJsonPath('steps.1.error', 'employees record #12345 was not found.');

        $this->actingAs($admin)->postJson("/api/v1/executions/{$id}/retry")->assertStatus(202)->assertJsonPath('data.retry_of_id', $id);
        $this->actingAs($admin)->postJson("/api/v1/executions/{$id}/cancel")->assertStatus(422);
    }

    #[Test]
    public function node_types_endpoint_lists_the_catalogue_with_implementation_labels(): void
    {
        $viewer = $this->userWithRole(Role::VIEWER);

        $response = $this->actingAs($viewer)->getJson('/api/v1/node-types')->assertOk();
        $types = collect($response->json('data'))->keyBy('type');

        $this->assertSame('simulated', $types['notify.email']['implementation']);
        $this->assertSame('implemented', $types['logic.if']['implementation']);
        $this->assertSame(['true', 'false'], $types['logic.if']['output_handles']);
    }
}
