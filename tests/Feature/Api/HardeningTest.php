<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowVariable;
use App\Models\Workspace;
use App\Services\ExecutionService;
use App\Services\WorkflowService;
use App\Workflow\Integrations\Laravel\SafeHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function workflows_always_land_in_a_workspace_and_never_leak_across_workspaces(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $membership = $admin->current_workspace_id;
        $admin->forceFill(['current_workspace_id' => null])->save();

        // No current workspace → falls back to one of the user's memberships.
        $id = $this->actingAs($admin)->postJson('/api/v1/workflows', ['name' => 'Fallback'])->assertCreated()->json('data.id');
        $this->assertSame($membership, Workflow::query()->find($id)?->workspace_id);

        // No membership at all → refused instead of creating a workspace-less workflow.
        $loner = $this->userWithRole(Role::ADMIN);
        $loner->workspaces()->detach();
        $loner->forceFill(['current_workspace_id' => null])->save();
        $this->actingAs($loner)->postJson('/api/v1/workflows', ['name' => 'Orphan'])->assertStatus(422);

        // A legacy workflow without a workspace is visible only to Super Admins.
        $legacy = Workflow::factory()->create(['workspace_id' => null, 'created_by' => $admin->id]);
        $outsider = $this->userWithRole(Role::ADMIN, Workspace::query()->create(['name' => 'Other', 'slug' => 'other']));
        $this->actingAs($outsider)->getJson("/api/v1/workflows/{$legacy->id}")->assertForbidden();
        $this->assertNotContains($legacy->id, array_column($this->actingAs($outsider)->getJson('/api/v1/workflows')->json('data'), 'id'));
        $this->actingAs($this->userWithRole(Role::SUPER_ADMIN))->getJson("/api/v1/workflows/{$legacy->id}")->assertOk();
    }

    #[Test]
    public function executions_of_deleted_workflows_cannot_be_retried(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);
        $failed = WorkflowExecution::query()->create(['workflow_id' => $workflow->id, 'trigger_type' => 'manual', 'status' => 'failed', 'mode' => 'test']);
        $workflow->delete();

        $this->actingAs($admin)->getJson("/api/v1/executions/{$failed->id}")->assertOk()->assertJsonPath('data.can.retry', false);
        $this->actingAs($admin)->postJson("/api/v1/executions/{$failed->id}/retry")->assertStatus(422);
        $this->assertSame(1, WorkflowExecution::query()->count());
    }

    #[Test]
    public function secret_variable_values_are_masked_in_stored_execution_data_and_logs(): void
    {
        Log::spy();
        $admin = $this->userWithRole(Role::ADMIN);
        $secret = new WorkflowVariable(['workspace_id' => $admin->current_workspace_id, 'key' => 'api_token', 'is_secret' => true]);
        $secret->value = 'tok-SuperSecret-9f8e7d';
        $secret->save();

        $workflow = $this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('vars', 'logic.set_variables', ['variables' => ['header' => 'Bearer-ish {{ variables.api_token }}']]),
            $this->node('note', 'data.execution_log', ['message' => 'Using {{ variables.api_token }}']),
            $this->node('log', 'notify.log', ['message' => 'token={{ variables.api_token }}']),
            $this->node('ret', 'utility.return', ['output' => ['used' => '{{ variables.api_token }}']]),
        ], [$this->edge('start', 'vars'), $this->edge('vars', 'note'), $this->edge('note', 'log'), $this->edge('log', 'ret')]);

        $execution = app(ExecutionService::class)->start($workflow, 'manual', [], $admin, 'test', null, [], true);
        $this->assertSame(ExecutionStatus::Success, $execution->status);

        $stored = json_encode([
            $execution->fresh()->result,
            $execution->steps()->get(['input_data', 'output_data'])->toArray(),
            $execution->logs()->get(['message', 'context'])->toArray(),
        ]);
        $this->assertStringNotContainsString('tok-SuperSecret-9f8e7d', (string) $stored);
        $this->assertStringContainsString('[REDACTED]', (string) $stored);

        Log::shouldNotHaveReceived('log', [\Mockery::any(), \Mockery::pattern('/tok-SuperSecret/'), \Mockery::any()]);
    }

    #[Test]
    public function outbound_requests_are_pinned_to_the_validated_ip(): void
    {
        $client = new SafeHttpClient;

        $this->assertSame(
            [CURLOPT_RESOLVE => ['example.com:443:93.184.216.34']],
            $client->pinnedCurlOptions('https://example.com/hook', ['93.184.216.34']),
        );
        $this->assertSame(
            [CURLOPT_RESOLVE => ['example.com:8080:[2606:2800:220:1::]']],
            $client->pinnedCurlOptions('http://example.com:8080/hook', ['2606:2800:220:1::']),
        );
    }

    #[Test]
    public function oversized_webhook_payloads_are_rejected_before_storage(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('hook', 'trigger.webhook')], []);
        app(WorkflowService::class)->activate($workflow, $admin);
        $workflow->refresh();

        $body = (string) json_encode(['blob' => str_repeat('x', 300 * 1024)]);
        $signature = 'sha256='.hash_hmac('sha256', $body, (string) $workflow->webhook_secret);

        $this->call('POST', "/api/v1/hooks/{$workflow->webhook_token}", [], [], [], [
            'HTTP_X_WORKFLOW_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(413);

        $this->assertSame(0, WorkflowExecution::query()->count());
    }

    #[Test]
    public function only_user_managers_can_browse_the_user_directory(): void
    {
        $viewer = $this->userWithRole(Role::VIEWER);
        $admin = $this->userWithRole(Role::ADMIN);

        $this->actingAs($viewer)->getJson('/api/v1/data/users')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/data/employees')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/data/users')->assertOk();
        $this->assertNotContains('users', array_column($this->actingAs($viewer)->getJson('/api/v1/data')->json('data'), 'alias'));
    }
}
