<?php

namespace Tests\Feature\Workflow;

use App\Enums\ExecutionStatus;
use App\Jobs\RunWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Services\ExecutionService;
use App\Workflow\Engine\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EngineTest extends TestCase
{
    use RefreshDatabase;

    private function run_(Workflow $workflow, array $payload = []): WorkflowExecution
    {
        return app(ExecutionService::class)->start($workflow, 'manual', $payload, null, 'test', null, [], true);
    }

    private function stepStatuses(WorkflowExecution $execution): array
    {
        return $execution->steps()->get()->mapWithKeys(fn ($s) => [$s->node_key => $s->status->value])->all();
    }

    #[Test]
    public function if_else_executes_only_the_matching_branch(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('check', 'logic.if', ['conditions' => [['left' => '{{ trigger.score }}', 'operator' => 'gte', 'right' => 50]]]),
            $this->node('high', 'utility.return', ['output' => ['branch' => 'high']]),
            $this->node('low', 'utility.return', ['output' => ['branch' => 'low']]),
        ], [
            $this->edge('start', 'check'),
            $this->edge('check', 'high', 'true'),
            $this->edge('check', 'low', 'false'),
        ]);

        $high = $this->run_($workflow, ['score' => 80]);
        $this->assertSame(ExecutionStatus::Success, $high->status);
        $this->assertSame(['branch' => 'high'], $high->result);
        $this->assertSame('skipped', $this->stepStatuses($high)['low']);

        $low = $this->run_($workflow, ['score' => 10]);
        $this->assertSame(['branch' => 'low'], $low->result);
        $this->assertSame('skipped', $this->stepStatuses($low)['high']);
    }

    #[Test]
    public function node_outputs_flow_downstream_and_inputs_outputs_and_timing_are_stored(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('vars', 'logic.set_variables', ['variables' => ['greeting' => 'Hello']]),
            $this->node('map', 'data.map_fields', ['mappings' => ['message' => '{{ variables.greeting }}, {{ trigger.name }}']]),
            $this->node('done', 'utility.return', ['output' => ['message' => '{{ nodes.map.output.result.message }}']]),
        ], [$this->edge('start', 'vars'), $this->edge('vars', 'map'), $this->edge('map', 'done')]);

        $execution = $this->run_($workflow, ['name' => 'Ada']);

        $this->assertSame(['message' => 'Hello, Ada'], $execution->result);
        $step = $execution->steps()->where('node_key', 'map')->first();
        $this->assertSame(['greeting' => 'Hello'], $step->input_data);
        $this->assertSame(['result' => ['message' => 'Hello, Ada']], $step->output_data);
        $this->assertNotNull($step->started_at);
        $this->assertNotNull($step->duration_ms);
        $this->assertNotNull($execution->duration_ms);
    }

    #[Test]
    public function failed_nodes_stop_the_workflow_by_default_and_skip_downstream(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('load', 'data.get_record', ['model' => 'employees', 'id' => 999]),
            $this->node('after', 'notify.log', ['message' => 'never']),
        ], [$this->edge('start', 'load'), $this->edge('load', 'after')]);

        $execution = $this->run_($workflow);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('was not found', $execution->error_message);
        $this->assertSame('failed', $this->stepStatuses($execution)['load']);
        $this->assertArrayNotHasKey('after', $this->stepStatuses($execution));
    }

    #[Test]
    public function continue_policy_records_the_error_and_finishes_as_partial(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('load', 'data.get_record', ['model' => 'employees', 'id' => 999], ['on_error' => 'continue']),
            $this->node('downstream', 'notify.log', ['message' => 'depends on load']),
            $this->node('independent', 'notify.log', ['message' => 'runs anyway']),
        ], [$this->edge('start', 'load'), $this->edge('load', 'downstream'), $this->edge('start', 'independent')]);

        $execution = $this->run_($workflow);
        $statuses = $this->stepStatuses($execution);

        $this->assertSame(ExecutionStatus::Partial, $execution->status);
        $this->assertSame('failed', $statuses['load']);
        $this->assertSame('skipped', $statuses['downstream']);
        $this->assertSame('success', $statuses['independent']);
        $this->assertSame(1, $execution->logs()->where('level', 'error')->count());
    }

    #[Test]
    public function retries_are_recorded_as_separate_attempts(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('load', 'data.get_record', ['model' => 'employees', 'id' => 999], ['retries' => 2, 'retry_backoff_ms' => 0]),
        ], [$this->edge('start', 'load')]);

        $execution = $this->run_($workflow);

        $this->assertSame([1, 2, 3], $execution->steps()->where('node_key', 'load')->pluck('attempt_number')->all());
    }

    #[Test]
    public function loops_run_the_body_per_item_then_the_done_branch(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('loop', 'logic.loop', ['items' => '{{ trigger.items }}']),
            $this->node('even', 'logic.if', ['conditions' => [['left' => '{{ item }}', 'operator' => 'in', 'right' => [2, 4]]]]),
            $this->node('log_even', 'data.execution_log', ['message' => 'even {{ item }}']),
            $this->node('done', 'utility.return', ['output' => ['iterations' => '{{ nodes.loop.output.iterations }}', 'evens' => '{{ nodes.log_even.success_count }}']]),
        ], [
            $this->edge('start', 'loop'),
            $this->edge('loop', 'even', 'each'),
            $this->edge('even', 'log_even', 'true'),
            $this->edge('loop', 'done', 'done'),
        ]);

        $execution = $this->run_($workflow, ['items' => [1, 2, 3, 4]]);

        $this->assertSame(ExecutionStatus::Success, $execution->status);
        $this->assertSame(['iterations' => 4, 'evens' => 2], $execution->result);
        $this->assertSame([1, 3], $execution->steps()->where('node_key', 'log_even')->pluck('iteration')->all());
    }

    #[Test]
    public function switch_and_merge_route_and_join_branches(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('route', 'logic.switch', ['value' => '{{ trigger.dept }}', 'cases' => [['value' => 'eng'], ['value' => 'ops']]]),
            $this->node('eng', 'logic.set_variables', ['variables' => ['team' => 'Engineering']]),
            $this->node('ops', 'logic.set_variables', ['variables' => ['team' => 'Operations']]),
            $this->node('other', 'logic.set_variables', ['variables' => ['team' => 'General']]),
            $this->node('merge', 'logic.merge'),
            $this->node('out', 'utility.return', ['output' => ['team' => '{{ variables.team }}', 'sources' => '{{ nodes.merge.output.sources }}']]),
        ], [
            $this->edge('start', 'route'),
            $this->edge('route', 'eng', 'case_0'),
            $this->edge('route', 'ops', 'case_1'),
            $this->edge('route', 'other', 'default'),
            $this->edge('eng', 'merge'), $this->edge('ops', 'merge'), $this->edge('other', 'merge'),
            $this->edge('merge', 'out'),
        ]);

        $this->assertSame(['team' => 'Operations', 'sources' => ['ops']], $this->run_($workflow, ['dept' => 'ops'])->result);
        $this->assertSame(['team' => 'General', 'sources' => ['other']], $this->run_($workflow, ['dept' => 'finance'])->result);
    }

    #[Test]
    public function stop_node_ends_the_execution_with_the_configured_status(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('stop', 'utility.stop', ['status' => 'failed', 'message' => 'Missing data']),
        ], [$this->edge('start', 'stop')]);

        $execution = $this->run_($workflow);
        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Missing data', $execution->error_message);
    }

    #[Test]
    public function node_execution_limit_prevents_runaway_loops(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('loop', 'logic.loop', ['items' => '{{ trigger.items }}']),
            $this->node('body', 'notify.log', ['message' => 'x']),
        ], [$this->edge('start', 'loop'), $this->edge('loop', 'body', 'each')], ['settings' => ['max_node_executions' => 10]]);

        $execution = $this->run_($workflow, ['items' => range(1, 50)]);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('Maximum node executions', $execution->error_message);
    }

    #[Test]
    public function recursive_subworkflows_are_blocked(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [$this->node('start', 'trigger.manual')], []);
        $workflow->update(['definition' => [
            'nodes' => [$this->node('start', 'trigger.manual'), $this->node('self', 'utility.subworkflow', ['workflow_id' => $workflow->id])],
            'edges' => [$this->edge('start', 'self')],
        ]]);

        $execution = $this->run_($workflow->refresh());

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('Recursive subworkflow call blocked', $execution->error_message);
    }

    #[Test]
    public function subworkflows_return_their_output_to_the_parent(): void
    {
        $user = $this->userWithRole();
        $child = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('ret', 'utility.return', ['output' => ['doubled' => '{{ trigger.n }}{{ trigger.n }}']]),
        ], [$this->edge('start', 'ret')]);
        $parent = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('call', 'utility.subworkflow', ['workflow_id' => $child->id, 'payload' => ['n' => '{{ trigger.n }}']]),
            $this->node('ret', 'utility.return', ['output' => ['child' => '{{ nodes.call.output.result.doubled }}']]),
        ], [$this->edge('start', 'call'), $this->edge('call', 'ret')]);

        $execution = $this->run_($parent, ['n' => 4]);

        $this->assertSame(['child' => '44'], $execution->result);
        $this->assertSame(1, WorkflowExecution::query()->where('parent_execution_id', $execution->id)->count());
    }

    #[Test]
    public function invalid_definitions_fail_without_running_nodes(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [$this->node('orphan', 'notify.log', ['message' => 'x'])], []);

        $execution = $this->run_($workflow);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('trigger', $execution->error_message);
        $this->assertSame(0, $execution->steps()->count());
    }

    #[Test]
    public function long_delays_park_the_execution_and_resume_from_the_queue(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('wait', 'logic.delay', ['seconds' => 3600]),
            $this->node('after', 'utility.return', ['output' => ['resumed' => true, 'name' => '{{ trigger.name }}']]),
        ], [$this->edge('start', 'wait'), $this->edge('wait', 'after')]);

        Queue::fake();
        $execution = $this->run_($workflow, ['name' => 'Ada']);

        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertNotNull($execution->resume_at);
        Queue::assertPushed(RunWorkflowJob::class, fn ($job) => $job->executionId === $execution->id);

        (new RunWorkflowJob($execution->id))->handle(app(WorkflowEngine::class));
        $execution->refresh();

        $this->assertSame(ExecutionStatus::Success, $execution->status);
        $this->assertSame(['resumed' => true, 'name' => 'Ada'], $execution->result);
        $this->assertSame(1, $execution->steps()->where('node_key', 'start')->count(), 'Nodes before the delay must not run twice');
    }

    #[Test]
    public function queued_executions_can_be_cancelled_and_failed_ones_retried(): void
    {
        Queue::fake();
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [$this->node('start', 'trigger.manual')], []);
        $service = app(ExecutionService::class);

        $queued = $service->start($workflow, 'manual', [], $user, 'test');
        $this->assertSame(ExecutionStatus::Queued, $queued->status);

        $cancelled = $service->cancel($queued);
        $this->assertSame(ExecutionStatus::Cancelled, $cancelled->status);

        // The job is a no-op for cancelled executions.
        (new RunWorkflowJob($queued->id))->handle(app(WorkflowEngine::class));
        $this->assertSame(ExecutionStatus::Cancelled, $queued->refresh()->status);

        $retry = $service->retry($cancelled, $user);
        $this->assertSame($cancelled->id, $retry->retry_of_id);
        $this->assertSame(ExecutionStatus::Queued, $retry->status);
    }

    #[Test]
    public function running_executions_are_cancelled_cooperatively(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('loop', 'logic.loop', ['items' => '{{ trigger.items }}']),
            $this->node('body', 'notify.log', ['message' => 'x']),
        ], [$this->edge('start', 'loop'), $this->edge('loop', 'body', 'each')]);

        $execution = WorkflowExecution::create(['workflow_id' => $workflow->id, 'workflow_version' => 1, 'trigger_type' => 'manual', 'trigger_data' => ['items' => [1, 2, 3]], 'status' => 'queued', 'mode' => 'test', 'cancel_requested_at' => now()]);
        app(WorkflowEngine::class)->run($execution);

        $this->assertSame(ExecutionStatus::Cancelled, $execution->refresh()->status);
    }

    #[Test]
    public function idempotency_keys_prevent_duplicate_executions(): void
    {
        Queue::fake();
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [$this->node('start', 'trigger.manual')], []);
        $service = app(ExecutionService::class);

        $first = $service->start($workflow, 'webhook', [], null, 'production', 'evt-1');
        $second = $service->start($workflow, 'webhook', [], null, 'production', 'evt-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WorkflowExecution::query()->count());
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    #[Test]
    public function a_node_can_be_re_executed_using_previous_upstream_output(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('vars', 'logic.set_variables', ['variables' => ['n' => '{{ trigger.n }}']]),
            $this->node('ret', 'utility.return', ['output' => ['n' => '{{ nodes.vars.output.n }}']]),
        ], [$this->edge('start', 'vars'), $this->edge('vars', 'ret')]);

        $this->run_($workflow, ['n' => 5]);

        $partial = app(ExecutionService::class)->fromNode($workflow, 'ret', $user);
        $partial->refresh();

        $this->assertSame(ExecutionStatus::Success, $partial->status, (string) $partial->error_message);
        $this->assertSame(['n' => 5], $partial->result);
        $this->assertSame(['ret'], $partial->steps()->pluck('node_key')->all());
    }
}
