<?php

namespace App\Workflow\Nodes\Utility;

use App\Enums\ExecutionStatus;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Engine\WorkflowEngine;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Nodes\AbstractNode;

class SubworkflowNode extends AbstractNode
{
    protected string $icon = 'workflow';

    public function type(): string
    {
        return 'utility.subworkflow';
    }

    public function label(): string
    {
        return 'Execute Subworkflow';
    }

    public function category(): string
    {
        return 'utility';
    }

    public function description(): string
    {
        return 'Runs another workflow of the same workspace synchronously and returns its output. Recursion is blocked.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('workflow_id', 'Workflow ID', 'number', ['required' => true, 'expression' => false, 'min' => 1]),
            $this->field('payload', 'Trigger payload', 'json', ['default' => []]),
            $this->field('fail_on_error', 'Fail if the subworkflow fails', 'boolean', ['default' => true, 'expression' => false]),
        ];
    }

    public function outputSchema(): array
    {
        return ['execution_id' => 'Child execution', 'status' => 'Child status', 'result' => 'Child Return Output'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $context = $input->context;
        $childId = (int) $input->get('workflow_id');
        $stack = [...$context->callStack, $context->workflow->id];

        if (in_array($childId, $stack, true)) {
            throw new NodeExecutionException("Recursive subworkflow call blocked (workflow #{$childId} is already running in this chain).");
        }

        if (count($stack) >= (int) config('workflows.max_subworkflow_depth', 3)) {
            throw new NodeExecutionException('Maximum subworkflow depth reached.');
        }

        $child = Workflow::query()->find($childId);
        if ($child === null || $child->workspace_id !== $context->workflow->workspace_id) {
            throw new NodeExecutionException("Subworkflow #{$childId} was not found in this workspace.");
        }

        $payload = $input->get('payload', []);
        $execution = WorkflowExecution::create([
            'workflow_id' => $child->id,
            'workflow_version' => $child->version,
            'trigger_type' => 'subworkflow',
            'trigger_data' => is_array($payload) ? $payload : ['value' => $payload],
            'status' => ExecutionStatus::Queued,
            'mode' => $context->execution->mode,
            'parent_execution_id' => $context->execution->id,
            'initiated_by' => $context->execution->initiated_by,
        ]);

        app(WorkflowEngine::class)->run($execution, $stack);
        $execution->refresh();

        // Subworkflows run synchronously inside the parent: a child that parks on a long
        // Delay would otherwise resume later on its own, detached from the parent.
        if ($execution->status === ExecutionStatus::Waiting) {
            $execution->transitionTo(ExecutionStatus::Cancelled, [
                'finished_at' => now(),
                'cancel_requested_at' => now(),
                'error_message' => 'Cancelled: subworkflows cannot pause (long Delay) while running inside a parent workflow.',
            ]);

            throw new NodeExecutionException("Subworkflow \"{$child->name}\" cannot pause on a long Delay while called from another workflow. Use delays of at most ".config('workflows.inline_delay_max_seconds', 5).' seconds in subworkflows.');
        }

        if ($input->get('fail_on_error', true) && in_array($execution->status, [ExecutionStatus::Failed, ExecutionStatus::Cancelled], true)) {
            throw new NodeExecutionException("Subworkflow \"{$child->name}\" {$execution->status->value}: {$execution->error_message}");
        }

        return NodeResult::ok([
            'execution_id' => $execution->id,
            'status' => $execution->status->value,
            'result' => $execution->result,
        ]);
    }
}
