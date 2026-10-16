<?php

namespace Tests\Feature\Api;

use App\Models\AiUsage;
use App\Models\Role;
use App\Models\WorkflowExecution;
use App\Models\WorkflowIntegration;
use App\Models\Workspace;
use App\Services\ExecutionService;
use App\Workflow\Support\AiUsageRecorder;
use Database\Seeders\IntegrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeClaude;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cost_estimates_use_the_configured_price_table(): void
    {
        $recorder = new AiUsageRecorder;

        $this->assertSame(0.00138, $recorder->estimate('claude-opus-5-5', 120, 45));
        $this->assertSame(0.000690, $recorder->estimate('claude-sonnet-5-5', 120, 45));
        $this->assertSame(0.0, $recorder->estimate('mock-text-1', 1000, 1000));
    }

    #[Test]
    public function ai_calls_are_recorded_and_reported_per_execution_and_on_the_dashboard(): void
    {
        $this->seed(IntegrationSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('write', 'ai.text', ['task' => 'generic', 'prompt' => 'Say hi']),
        ], [$this->edge('start', 'write')]);
        $service = app(ExecutionService::class);

        // 1) Mock adapter: recorded as simulated with zero cost.
        $mocked = $service->start($workflow, 'manual', [], $admin, 'test', null, [], true);

        // 2) Claude enabled: real usage and estimated cost.
        (new FakeClaude)->bind($this->app)->push(FakeClaude::json(['text' => 'Hi!']));
        WorkflowIntegration::query()->where('key', 'anthropic')->firstOrFail()
            ->forceFill(['credentials' => ['api_key' => 'sk-ant-test'], 'configuration' => ['enabled' => true, 'model' => 'claude-opus-5-5', 'effort' => 'low']])->save();
        $real = $service->start($workflow, 'manual', [], $admin, 'test', null, [], true);

        $this->assertDatabaseHas('ai_usage', ['execution_id' => $mocked->id, 'simulated' => true, 'provider' => 'mock', 'estimated_cost_usd' => 0]);
        $row = AiUsage::query()->where('execution_id', $real->id)->sole();
        $this->assertFalse($row->simulated);
        $this->assertSame(['anthropic', 'claude-opus-5-5', 120, 45], [$row->provider, $row->model, $row->input_tokens, $row->output_tokens]);
        $this->assertEqualsWithDelta(0.00138, (float) $row->estimated_cost_usd, 1e-9);
        $this->assertSame($workflow->workspace_id, $row->workspace_id);

        $this->actingAs($admin)->getJson("/api/v1/executions/{$real->id}")->assertOk()
            ->assertJsonPath('ai_usage.0.model', 'claude-opus-5-5')
            ->assertJsonPath('ai_usage.0.input_tokens', 120);

        $usage = $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk()->json('ai_usage');
        $this->assertSame(2, $usage['calls']);
        $this->assertSame(1, $usage['real_calls']);
        $this->assertSame(1, $usage['simulated_calls']);
        $this->assertEqualsWithDelta(0.0014, $usage['estimated_cost_usd'], 1e-9);
        $this->assertCount(2, $usage['by_model']);

        // Other workspaces don't see this usage.
        $outsider = $this->userWithRole(Role::ADMIN, Workspace::query()->create(['name' => 'Other', 'slug' => 'other']));
        $this->assertSame(0, $this->actingAs($outsider)->getJson('/api/v1/dashboard')->json('ai_usage.calls'));
    }

    #[Test]
    public function usage_history_survives_execution_pruning(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('write', 'ai.text', ['task' => 'generic', 'prompt' => 'Say hi']),
        ], [$this->edge('start', 'write')]);
        $execution = app(ExecutionService::class)->start($workflow, 'manual', [], $admin, 'test', null, [], true);

        WorkflowExecution::query()->whereKey($execution->id)->update(['created_at' => now()->subDays(90)]);
        $this->artisan('model:prune', ['--model' => [WorkflowExecution::class]])->assertSuccessful();

        $this->assertDatabaseMissing('workflow_executions', ['id' => $execution->id]);
        $this->assertDatabaseHas('ai_usage', ['workflow_id' => $workflow->id, 'execution_id' => null]);
    }
}
