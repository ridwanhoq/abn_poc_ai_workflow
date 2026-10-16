<?php

namespace App\Jobs;

use App\Enums\ExecutionStatus;
use App\Models\WorkflowExecution;
use App\Workflow\Engine\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Runs (or resumes) one workflow execution on the queue. Node-level retries
 * are handled by the engine, so the job itself is attempted once.
 */
class RunWorkflowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * @param  int|null  $timeout  seconds; callers pass the workflow's timeout setting plus a margin
     */
    public function __construct(public readonly int $executionId, ?int $timeout = null)
    {
        if ($timeout !== null) {
            $this->timeout = max(60, $timeout);
        }
    }

    /**
     * Job timeout for a workflow: its own timeout setting plus a margin for bookkeeping.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function timeoutFor(array $settings): int
    {
        return (int) ($settings['timeout_seconds'] ?? config('workflows.default_settings.timeout_seconds')) + 60;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("workflow-execution:{$this->executionId}"))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(WorkflowEngine $engine): void
    {
        $execution = WorkflowExecution::query()->find($this->executionId);

        if ($execution === null || $execution->status->isFinished()) {
            return; // cancelled while queued, or already processed
        }

        $engine->run($execution);
    }

    public function failed(?Throwable $exception): void
    {
        $execution = WorkflowExecution::query()->find($this->executionId);

        // A duplicate delivery (e.g. after retry_after) of a run another worker is still
        // executing must not overwrite that run's status.
        if ($exception instanceof MaxAttemptsExceededException && $execution?->status === ExecutionStatus::Running) {
            return;
        }

        if ($execution && ! $execution->status->isFinished()) {
            $execution->forceFill([
                'status' => ExecutionStatus::Failed,
                'finished_at' => now(),
                'error_message' => 'The queue worker stopped this execution (timeout or worker failure).',
            ])->save();
        }
    }
}
