<?php

namespace App\Services;

use App\Enums\ExecutionStatus;
use App\Jobs\RunWorkflowJob;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Workflow\Engine\Graph;
use App\Workflow\Engine\WorkflowEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Creates, dispatches, cancels and retries executions.
 */
class ExecutionService
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * Create an execution and queue it. When an idempotency key is supplied and
     * an execution with that key already exists for the workflow, the existing
     * execution is returned instead (wasRecentlyCreated === false).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra
     */
    public function start(
        Workflow $workflow,
        string $triggerType,
        array $payload,
        ?User $user = null,
        string $mode = 'production',
        ?string $idempotencyKey = null,
        array $extra = [],
        bool $sync = false,
    ): WorkflowExecution {
        if ($workflow->trashed()) {
            throw ValidationException::withMessages(['workflow' => 'This workflow was deleted and can no longer run.']);
        }

        if ($idempotencyKey !== null) {
            $existing = $this->findByKey($workflow, $idempotencyKey);
            if ($existing) {
                return $existing;
            }
        }

        try {
            $execution = WorkflowExecution::create([
                'workflow_id' => $workflow->id,
                'workflow_version' => $workflow->version,
                'trigger_type' => $triggerType,
                'trigger_data' => $payload,
                'status' => ExecutionStatus::Queued,
                'mode' => $mode,
                'initiated_by' => $user?->id,
                'idempotency_key' => $idempotencyKey,
                ...$extra,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent dispatch of the same event.
            return $this->findByKey($workflow, (string) $idempotencyKey);
        }

        if ($sync) {
            $this->engine->run($execution);
            $execution->refresh();
        } else {
            RunWorkflowJob::dispatch($execution->id, RunWorkflowJob::timeoutFor($workflow->effectiveSettings()))
                ->onQueue(config('workflows.queue'));
        }

        return $execution;
    }

    /**
     * Manual / test run. Draft workflows may only be run in test mode; the trigger
     * node's sample payload is used when no payload is supplied.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function manual(Workflow $workflow, User $user, ?array $payload = null, bool $test = true): WorkflowExecution
    {
        $trigger = $workflow->triggerNode();
        $payload ??= (array) ($trigger['config']['sample_payload'] ?? []);
        $mode = $test || ! $workflow->isActive() ? 'test' : 'production';

        return $this->start($workflow, 'manual', $payload, $user, $mode);
    }

    public function cancel(WorkflowExecution $execution): WorkflowExecution
    {
        if (! $execution->status->isCancellable()) {
            throw ValidationException::withMessages(['execution' => 'Only queued, running or waiting executions can be cancelled.']);
        }

        if (in_array($execution->status, [ExecutionStatus::Queued, ExecutionStatus::Waiting], true)) {
            $execution->transitionTo(ExecutionStatus::Cancelled, [
                'cancel_requested_at' => now(),
                'finished_at' => now(),
                'error_message' => 'Cancelled before it ran.',
            ]);
        } else {
            // Cooperative: the engine checks this flag between nodes.
            $execution->forceFill(['cancel_requested_at' => now()])->save();
        }

        return $execution->refresh();
    }

    public function retry(WorkflowExecution $execution, User $user): WorkflowExecution
    {
        if (! $execution->status->isFinished() || $execution->status === ExecutionStatus::Success) {
            throw ValidationException::withMessages(['execution' => 'Only failed, partial or cancelled executions can be retried.']);
        }

        return $this->start(
            $execution->workflow,
            $execution->trigger_type,
            $execution->trigger_data ?? [],
            $user,
            $execution->mode,
            null,
            ['retry_of_id' => $execution->id],
        );
    }

    /**
     * Re-run a node and its descendants using upstream outputs from the most
     * recent finished execution of the same workflow.
     */
    public function fromNode(Workflow $workflow, string $nodeKey, User $user): WorkflowExecution
    {
        $graph = new Graph($workflow->definition);

        if (! isset($graph->nodes[$nodeKey])) {
            throw ValidationException::withMessages(['node_key' => 'Node not found in the saved workflow.']);
        }
        if ($graph->enclosingLoop($nodeKey) !== null) {
            throw ValidationException::withMessages(['node_key' => 'Nodes inside a loop cannot be executed individually.']);
        }

        $source = $workflow->executions()
            ->whereIn('status', ['success', 'partial', 'failed'])
            ->whereNotNull('state')
            ->latest('id')
            ->first();

        $states = $source?->state['node_states'] ?? [];
        $missing = [];
        foreach ($graph->incoming[$nodeKey] as $edge) {
            if (($states[$edge['source']]['status'] ?? null) !== 'success') {
                $missing[] = $edge['source'];
            }
        }

        if ($source === null || ($graph->incoming[$nodeKey] !== [] && count($missing) === count($graph->incoming[$nodeKey]))) {
            throw ValidationException::withMessages(['node_key' => 'No previous execution has output for the nodes feeding this one. Run the full workflow first.']);
        }

        return $this->start($workflow, $source->trigger_type, $source->trigger_data ?? [], $user, 'test', null, [
            'start_node_key' => $nodeKey,
            'retry_of_id' => $source->id,
            'state' => ['seed_node_states' => $states, 'seed_variables' => $source->state['variables'] ?? []],
        ]);
    }

    private function findByKey(Workflow $workflow, string $key): ?WorkflowExecution
    {
        return WorkflowExecution::query()
            ->where('workflow_id', $workflow->id)
            ->where('idempotency_key', $key)
            ->first();
    }
}
