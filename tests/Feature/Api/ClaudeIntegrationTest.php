<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Models\Role;
use App\Models\WorkflowIntegration;
use App\Services\ExecutionService;
use Database\Seeders\IntegrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeClaude;
use Tests\TestCase;

class ClaudeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): WorkflowIntegration
    {
        return WorkflowIntegration::query()->where('key', 'anthropic')->firstOrFail();
    }

    #[Test]
    public function claude_is_opt_in_and_the_key_is_never_exposed(): void
    {
        $this->seed(IntegrationSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);
        $integration = $this->integration();
        $config = ['enabled' => true, 'model' => 'claude-opus-5-5', 'effort' => 'low'];

        $this->actingAs($admin)->putJson("/api/v1/integrations/{$integration->id}", ['configuration' => $config])
            ->assertStatus(422)->assertJsonValidationErrors('configuration.enabled');

        $this->actingAs($admin)->putJson("/api/v1/integrations/{$integration->id}", ['configuration' => [...$config, 'model' => 'gpt-4']])
            ->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/v1/integrations/{$integration->id}", ['credentials' => ['api_key' => 'sk-ant-secret-123']])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/integrations/{$integration->id}", ['configuration' => $config])->assertOk();

        $this->assertNotSame('sk-ant-secret-123', WorkflowIntegration::query()->whereKey($integration->id)->toBase()->value('credentials'), 'Stored encrypted');

        $index = $this->actingAs($admin)->getJson('/api/v1/integrations')->assertOk();
        $this->assertStringNotContainsString('sk-ant-secret-123', $index->getContent());
        $this->assertSame('connected', collect($index->json('data'))->firstWhere('key', 'anthropic')['status']);

        $types = collect($this->actingAs($admin)->getJson('/api/v1/node-types')->json('data'))->keyBy('type');
        $this->assertSame('implemented', $types['ai.text']['implementation']);

        $this->actingAs($this->userWithRole(Role::VIEWER))->putJson("/api/v1/integrations/{$integration->id}", ['configuration' => [...$config, 'enabled' => false]])->assertForbidden();
    }

    #[Test]
    public function an_enabled_integration_routes_ai_text_nodes_to_claude(): void
    {
        $this->seed(IntegrationSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);
        $fake = (new FakeClaude)->bind($this->app)
            ->push(FakeClaude::json(['subject' => 'We miss you, Ada', 'text' => 'Hi Ada, new dramas are waiting.', 'offer' => 'a curated watchlist', 'channel' => 'email']));

        $this->integration()->forceFill([
            'credentials' => ['api_key' => 'sk-ant-test'],
            'configuration' => ['enabled' => true, 'model' => 'claude-sonnet-5-5', 'effort' => 'medium'],
        ])->save();

        $workflow = $this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('write', 'ai.text', ['task' => 'retention_message', 'prompt' => 'Win back {{ trigger.name }}', 'context' => ['name' => '{{ trigger.name }}']]),
            $this->node('ret', 'utility.return', ['output' => ['subject' => '{{ nodes.write.output.subject }}', 'simulated' => '{{ nodes.write.output.simulated }}']]),
        ], [$this->edge('start', 'write'), $this->edge('write', 'ret')]);

        $execution = app(ExecutionService::class)->start($workflow, 'manual', ['name' => 'Ada'], $admin, 'test', null, [], true);

        $this->assertSame(ExecutionStatus::Success, $execution->status, (string) $execution->error_message);
        $this->assertSame(['subject' => 'We miss you, Ada', 'simulated' => false], $execution->result);
        $this->assertSame('claude-sonnet-5-5', $fake->requests[0]['body']['model']);
        $this->assertSame('medium', $fake->requests[0]['body']['output_config']['effort']);
        $this->assertStringContainsString('"name": "Ada"', $fake->requests[0]['body']['messages'][0]['content']);
    }

    #[Test]
    public function claude_errors_fail_the_node_and_disabled_integration_uses_the_mock(): void
    {
        $this->seed(IntegrationSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);
        (new FakeClaude)->bind($this->app)->push(FakeClaude::error(401, 'authentication_error'));
        $this->integration()->forceFill(['credentials' => ['api_key' => 'bad'], 'configuration' => ['enabled' => true, 'model' => 'claude-opus-5-5', 'effort' => 'low']])->save();

        $workflow = $this->workflowFor($admin, [
            $this->node('start', 'trigger.manual'),
            $this->node('write', 'ai.text', ['task' => 'generic', 'prompt' => 'Hi']),
        ], [$this->edge('start', 'write')]);

        $failed = app(ExecutionService::class)->start($workflow, 'manual', [], $admin, 'test', null, [], true);
        $this->assertSame(ExecutionStatus::Failed, $failed->status);
        $this->assertStringContainsString('rejected the API key', (string) $failed->error_message);

        $this->integration()->forceFill(['configuration' => ['enabled' => false, 'model' => 'claude-opus-5-5', 'effort' => 'low']])->save();
        $mocked = app(ExecutionService::class)->start($workflow, 'manual', [], $admin, 'test', null, [], true);
        $this->assertSame(ExecutionStatus::Success, $mocked->status);
        $this->assertTrue($mocked->steps()->where('node_key', 'write')->first()->output_data['simulated']);
    }

    #[Test]
    public function the_check_button_verifies_the_stored_key_with_a_real_request(): void
    {
        $this->seed(IntegrationSeeder::class);
        $admin = $this->userWithRole(Role::ADMIN);
        $fake = (new FakeClaude)->bind($this->app)->push(FakeClaude::json(['text' => 'OK']));
        $integration = $this->integration();
        $integration->forceFill(['credentials' => ['api_key' => 'sk-ant-test']])->save();

        $this->actingAs($admin)->postJson("/api/v1/integrations/{$integration->id}/test")
            ->assertOk()->assertJson(['ok' => true, 'status' => 'not_configured']);
        $this->assertCount(1, $fake->requests);
    }
}
