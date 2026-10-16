<?php

namespace Tests\Feature\Workflow;

use App\Enums\ExecutionStatus;
use App\Jobs\RunWorkflowJob;
use App\Models\WorkflowExecution;
use App\Services\ExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class QueueRobustnessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_job_timeout_follows_the_workflow_timeout_setting(): void
    {
        Queue::fake();
        $user = $this->userWithRole();
        $default = $this->workflowFor($user, [$this->node('start', 'trigger.manual')], []);
        $long = $this->workflowFor($user, [$this->node('start', 'trigger.manual')], [], ['settings' => ['timeout_seconds' => 1200]]);

        app(ExecutionService::class)->start($default, 'manual', [], $user, 'test');
        app(ExecutionService::class)->start($long, 'manual', [], $user, 'test');

        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job) => $job->timeout === 360);
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job) => $job->timeout === 1260);
    }

    #[Test]
    public function queue_retry_after_exceeds_the_longest_allowed_workflow(): void
    {
        // Max workflow timeout is 3600 s; a smaller retry_after would let a second worker
        // pick up a job that is still running.
        $this->assertGreaterThan(3660, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan(3660, config('queue.connections.redis.retry_after'));
    }

    #[Test]
    public function a_duplicate_delivery_does_not_fail_an_execution_that_is_still_running(): void
    {
        $user = $this->userWithRole();
        $workflow = $this->workflowFor($user, [$this->node('start', 'trigger.manual')], []);
        $running = WorkflowExecution::query()->create(['workflow_id' => $workflow->id, 'trigger_type' => 'manual', 'status' => 'running', 'mode' => 'test', 'started_at' => now()]);
        $queued = WorkflowExecution::query()->create(['workflow_id' => $workflow->id, 'trigger_type' => 'manual', 'status' => 'queued', 'mode' => 'test']);

        (new RunWorkflowJob($running->id))->failed(new MaxAttemptsExceededException('duplicate delivery'));
        $this->assertSame(ExecutionStatus::Running, $running->fresh()->status, 'Another worker owns this run');

        (new RunWorkflowJob($queued->id))->failed(new RuntimeException('worker crashed'));
        $this->assertSame(ExecutionStatus::Failed, $queued->fresh()->status);
    }

    #[Test]
    public function subworkflows_that_would_pause_fail_the_parent_node_clearly(): void
    {
        Queue::fake();
        $user = $this->userWithRole();
        $child = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('wait', 'logic.delay', ['seconds' => 3600]),
        ], [$this->edge('start', 'wait')]);
        $parent = $this->workflowFor($user, [
            $this->node('start', 'trigger.manual'),
            $this->node('call', 'utility.subworkflow', ['workflow_id' => $child->id]),
            $this->node('after', 'notify.log', ['message' => 'must not run']),
        ], [$this->edge('start', 'call'), $this->edge('call', 'after')]);

        $execution = app(ExecutionService::class)->start($parent, 'manual', [], $user, 'test', null, [], true);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('cannot pause', (string) $execution->error_message);
        $childRun = WorkflowExecution::query()->where('parent_execution_id', $execution->id)->sole();
        $this->assertSame(ExecutionStatus::Cancelled, $childRun->status, 'The parked child must not resume on its own later');
        $this->assertSame(0, $execution->steps()->where('node_key', 'after')->where('status', 'success')->count());
    }
}
