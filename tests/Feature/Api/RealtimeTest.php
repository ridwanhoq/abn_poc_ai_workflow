<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Events\ExecutionUpdated;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\Workspace;
use App\Services\ExecutionService;
use App\Workflow\Support\ExecutionBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1, // nothing listens here
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        // Channels registered at boot belong to the boot-time (null) driver; register them on Reverb.
        require base_path('routes/channels.php');
    }

    private function loopWorkflow(User $owner): Workflow
    {
        return $this->workflowFor($owner, [
            $this->node('start', 'trigger.manual'),
            $this->node('loop', 'logic.loop', ['items' => '{{ trigger.items }}']),
            $this->node('body', 'notify.log', ['message' => 'x']),
        ], [$this->edge('start', 'loop'), $this->edge('loop', 'body', 'each')]);
    }

    #[Test]
    public function status_changes_are_broadcast_and_progress_is_throttled(): void
    {
        Event::fake([ExecutionUpdated::class]);
        $admin = $this->userWithRole(Role::ADMIN);

        $execution = app(ExecutionService::class)->start($this->loopWorkflow($admin), 'manual', ['items' => range(1, 40)], $admin, 'test', null, [], true);

        $this->assertSame(ExecutionStatus::Success, $execution->status);
        $statuses = [];
        Event::assertDispatched(ExecutionUpdated::class, function (ExecutionUpdated $event) use (&$statuses) {
            $statuses[] = $event->broadcastWith()['status'];

            return true;
        });

        // queued (created) → running → success are always sent…
        $this->assertContains('queued', $statuses);
        $this->assertContains('running', $statuses);
        $this->assertSame('success', end($statuses));
        // …while 42 node executions produce only a handful of throttled progress events.
        $this->assertLessThan(10, count($statuses));
    }

    #[Test]
    public function broadcasts_carry_only_ids_and_status_on_private_channels(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);
        $execution = WorkflowExecution::query()->create(['workflow_id' => $workflow->id, 'trigger_type' => 'manual', 'trigger_data' => ['email' => 'secret@example.test'], 'status' => 'queued', 'mode' => 'test']);
        $event = new ExecutionUpdated($execution);

        $this->assertSame(['id', 'workflow_id', 'status'], array_keys($event->broadcastWith()));
        $this->assertSame(
            ["private-executions.{$execution->id}", "private-workspaces.{$workflow->workspace_id}.executions"],
            array_map(fn ($c) => $c->name, $event->broadcastOn()),
        );
        $this->assertSame('execution.updated', $event->broadcastAs());
    }

    #[Test]
    public function an_unreachable_reverb_server_never_breaks_execution(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $started = microtime(true);

        $execution = app(ExecutionService::class)->start($this->loopWorkflow($admin), 'manual', ['items' => range(1, 20)], $admin, 'test', null, [], true);

        $this->assertSame(ExecutionStatus::Success, $execution->status);
        $this->assertFalse(app(ExecutionBroadcaster::class)->enabled(), 'Circuit breaker opened after the first failure');
        $this->assertLessThan(5, microtime(true) - $started, 'A dead Reverb server must not slow down the run');
    }

    #[Test]
    public function private_channels_use_the_same_authorization_as_the_api(): void
    {
        $admin = $this->userWithRole(Role::ADMIN);
        $outsider = $this->userWithRole(Role::ADMIN, Workspace::query()->create(['name' => 'Other', 'slug' => 'other']));
        $workflow = $this->workflowFor($admin, [$this->node('start', 'trigger.manual')], []);
        $execution = WorkflowExecution::query()->create(['workflow_id' => $workflow->id, 'trigger_type' => 'manual', 'status' => 'success', 'mode' => 'test']);
        $auth = fn ($user, string $channel) => $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $auth($admin, "private-executions.{$execution->id}")->assertOk()->assertJsonStructure(['auth']);
        $auth($admin, "private-workspaces.{$workflow->workspace_id}.executions")->assertOk();
        $auth($outsider, "private-executions.{$execution->id}")->assertForbidden();
        $auth($outsider, "private-workspaces.{$workflow->workspace_id}.executions")->assertForbidden();
        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-executions.{$execution->id}"])->assertForbidden();
    }
}
