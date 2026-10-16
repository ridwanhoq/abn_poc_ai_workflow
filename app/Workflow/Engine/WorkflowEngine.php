<?php

namespace App\Workflow\Engine;

use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Events\WorkflowExecutionFinished;
use App\Jobs\RunWorkflowJob;
use App\Models\WorkflowExecution;
use App\Models\WorkflowExecutionStep;
use App\Models\WorkflowVariable;
use App\Models\WorkflowVersion;
use App\Workflow\Contracts\NodeType;
use App\Workflow\Data\CursorStats;
use App\Workflow\Exceptions\ExecutionCancelledException;
use App\Workflow\Exceptions\ExpressionException;
use App\Workflow\Exceptions\InvalidWorkflowException;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Exceptions\WorkflowFailedException;
use App\Workflow\NodeRegistry;
use App\Workflow\Nodes\AbstractNode;
use App\Workflow\Support\ExecutionBroadcaster;
use App\Workflow\Support\Redactor;
use App\Workflow\Triggers\TriggerDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Executes a saved workflow graph.
 *
 * Model: nodes run sequentially in topological order. An edge is "active" when
 * its source node executed successfully and returned that output handle. A
 * node runs when at least one incoming edge is active, otherwise it is
 * skipped. Loop nodes run their "each" sub-graph once per item and then
 * activate "done". The design keeps per-scope bookkeeping in RunFrame so that
 * parallel branch execution can be introduced later without changing nodes.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly ExpressionResolver $resolver,
        private readonly GraphValidator $validator,
        private readonly Redactor $redactor,
        private readonly ExecutionBroadcaster $broadcaster,
    ) {}

    /**
     * @param  list<int>  $callStack  parent workflow IDs (subworkflow recursion guard)
     */
    public function run(WorkflowExecution $execution, array $callStack = []): void
    {
        TriggerDispatcher::$engineDepth++;

        try {
            $this->execute($execution, $callStack);
        } finally {
            TriggerDispatcher::$engineDepth--;
        }
    }

    /**
     * @param  list<int>  $callStack
     */
    private function execute(WorkflowExecution $execution, array $callStack): void
    {
        $execution->refresh();

        if ($execution->status->isFinished() || $execution->status === ExecutionStatus::Running) {
            return; // already processed (duplicate job delivery) or picked up elsewhere
        }

        $workflow = $execution->workflow;
        $definition = $this->definitionFor($execution);
        $graph = new Graph($definition);
        $context = new ExecutionContext($execution, $workflow, $execution->trigger_data ?? [], $workflow->effectiveSettings(), $callStack);
        $variables = $this->globalVariables($workflow->workspace_id);
        $context->variables = array_map(fn ($v) => $v['value'], $variables);
        $context->secrets = array_values(array_map(fn ($v) => (string) $v['value'], array_filter($variables, fn ($v) => $v['secret'] && $v['value'] !== null)));
        $frame = new RunFrame;

        $state = $execution->state ?? [];
        if ($execution->status === ExecutionStatus::Waiting && ($state['resume'] ?? false)) {
            $this->restore($context, $frame, $state);
        } elseif ($execution->start_node_key) {
            $this->preparePartialRun($graph, $context, $frame, $execution->start_node_key, $state);
        }

        $execution->transitionTo(ExecutionStatus::Running, [
            'started_at' => $execution->started_at ?? now(),
            'resume_at' => null,
        ]);

        $status = ExecutionStatus::Success;
        $error = null;

        try {
            $validation = $this->validator->validate($definition);
            if (! $validation['valid']) {
                throw new InvalidWorkflowException('Workflow definition is invalid: '.$validation['errors'][0]['message'], $validation['errors']);
            }

            $signal = $this->runScope($graph, $graph->orderedScope(), $context, $frame);

            if ($signal?->type === 'wait') {
                $this->park($execution, $context, $frame, (int) $signal->seconds);

                return;
            }

            if ($context->hadFailures) {
                $status = ExecutionStatus::Partial;
                $error = 'One or more nodes failed and were skipped by the "continue" failure policy.';
            }
        } catch (ExecutionCancelledException $e) {
            $status = ExecutionStatus::Cancelled;
            $error = $e->getMessage();
        } catch (WorkflowFailedException|InvalidWorkflowException $e) {
            $status = ExecutionStatus::Failed;
            $error = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $status = ExecutionStatus::Failed;
            $error = 'Unexpected engine error. See application logs for details.';
        }

        $this->finish($execution, $context, $status, $error);
    }

    /**
     * Run an ordered list of node keys within one scope.
     *
     * @param  list<string>  $keys
     */
    private function runScope(Graph $graph, array $keys, ExecutionContext $context, RunFrame $frame): ?ScopeSignal
    {
        foreach ($keys as $key) {
            if (isset($frame->decided[$key])) {
                continue;
            }

            $type = $graph->typeOf($key);
            $forced = $frame->forceStart === $key;

            if ($this->registry->isTrigger($type)) {
                $data = $context->trigger;
            } else {
                $sources = $this->activeSources($graph, $key, $frame);

                if ($sources === [] && ! $forced) {
                    $frame->decided[$key] = 'skipped';
                    if (! $context->inLoop()) {
                        $this->recordSkipped($graph, $key, $context);
                    }

                    continue;
                }

                if ($forced && $sources === []) {
                    $sources = array_values(array_filter(
                        array_map(fn ($e) => $e['source'], $graph->incoming[$key]),
                        fn ($s) => isset($context->nodeStates[$s]['output']),
                    ));
                }

                $data = match (true) {
                    $type === 'logic.merge' => ['inputs' => array_combine($sources, array_map(fn ($s) => $context->nodeStates[$s]['output'] ?? null, $sources)) ?: []],
                    // Nodes directly on a loop's "each" output receive the current item.
                    $context->inLoop() && $this->viaEach($graph, $key, $sources[0] ?? '') => $context->scope()['item'],
                    default => $context->nodeStates[$sources[0] ?? '']['output'] ?? null,
                };
            }

            $result = $this->executeNode($graph, $key, $data, $context);

            if ($result === null) {
                $frame->decided[$key] = 'failed';

                continue;
            }

            $frame->decided[$key] = 'executed';
            $frame->handles[$key] = $result->handles ?? $this->handlesFor($graph, $key);

            if ($result->stop) {
                if ($result->stopStatus === 'failed') {
                    throw new WorkflowFailedException((string) ($result->output['message'] ?? 'Stopped by workflow.'));
                }

                return new ScopeSignal('stop');
            }

            if ($result->waitSeconds !== null) {
                if ($context->inLoop()) {
                    throw new WorkflowFailedException('Long delays are not supported inside loops.');
                }

                return new ScopeSignal('wait', $result->waitSeconds);
            }

            if ($result->items !== null) {
                $signal = $this->runLoop($graph, $key, $result, $context, $frame);
                if ($signal !== null) {
                    return $signal;
                }
            }
        }

        return null;
    }

    private function runLoop(Graph $graph, string $loopKey, NodeResult $result, ExecutionContext $context, RunFrame $frame): ?ScopeSignal
    {
        $keys = $graph->orderedScope($graph->loopBody($loopKey));
        $iterations = 0;
        $signal = null;

        foreach ($result->items as $item) {
            $context->guard();
            $context->pushItem($item, $iterations);

            $inner = new RunFrame;
            $inner->decided[$loopKey] = 'executed';
            $inner->handles[$loopKey] = ['each'];

            try {
                $signal = $this->runScope($graph, $keys, $context, $inner);
            } finally {
                $context->popItem();
            }

            $iterations++;

            if ($signal !== null) {
                break;
            }
        }

        $output = $result->output;
        $stats = $output['_stats'] ?? null;
        unset($output['_stats']);
        $output['iterations'] = $iterations;
        if ($stats instanceof CursorStats) {
            $output['batches'] = $stats->batches;
        }

        $context->nodeStates[$loopKey]['output'] = $output;
        $frame->handles[$loopKey] = ['done'];

        // Update the loop's own step with the final iteration count.
        if ($result->stepId !== null) {
            WorkflowExecutionStep::query()->whereKey($result->stepId)->update([
                'output_data' => json_encode($this->redactor->forStorage($output, $context->secrets)),
            ]);
        }

        return $signal;
    }

    /**
     * Execute one node with retries and the configured failure policy.
     * Returns null when the node failed and the policy says "continue".
     */
    private function executeNode(Graph $graph, string $key, mixed $data, ExecutionContext $context): ?NodeResult
    {
        $node = $graph->nodes[$key];
        $nodeType = $this->registry->get((string) $node['type']);
        $settings = (array) ($node['settings'] ?? []);
        $retries = max(0, min(5, (int) ($settings['retries'] ?? $context->settings['default_retries'] ?? 0)));
        $backoff = max(0, min(10000, (int) ($settings['retry_backoff_ms'] ?? $context->settings['retry_backoff_ms'] ?? 500)));
        $policy = ($settings['on_error'] ?? 'inherit') === 'inherit'
            ? ($context->settings['failure_policy'] ?? 'stop')
            : $settings['on_error'];
        $rawConfig = array_merge($nodeType instanceof AbstractNode ? $nodeType->defaults() : [], (array) ($node['config'] ?? []));
        $name = (string) ($node['name'] ?? $nodeType->label());

        for ($attempt = 1; ; $attempt++) {
            $context->guard();
            $context->nodeExecutions++;
            $context->currentNodeKey = $key;
            $started = microtime(true);

            $step = WorkflowExecutionStep::create([
                'execution_id' => $context->execution->id,
                'node_key' => $key,
                'node_type' => $nodeType->type(),
                'node_name' => $name,
                'status' => StepStatus::Running,
                'input_data' => $this->redactor->forStorage($data, $context->secrets),
                'attempt_number' => $attempt,
                'iteration' => $context->currentIteration(),
                'sequence' => ++$context->sequence,
                'started_at' => Carbon::now(),
            ]);

            try {
                $config = $this->resolveConfig($nodeType, $rawConfig, $context);
                $result = $nodeType->execute(new NodeInput($node, $config, $rawConfig, $data, $context, $this->resolver));
                $output = $result->output;
                $stored = $output;
                unset($stored['_stats']);

                $step->update([
                    'status' => StepStatus::Success,
                    'output_data' => $this->redactor->forStorage($stored, $context->secrets),
                    'finished_at' => Carbon::now(),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);

                $context->recordNode($key, 'success', $stored, $result->handles ?? $this->handlesFor($graph, $key));

                $result->stepId = $step->id;
                $this->broadcaster->notify($context->execution);

                return $result;
            } catch (ExecutionCancelledException|WorkflowFailedException $e) {
                $step->update([
                    'status' => $e instanceof ExecutionCancelledException ? StepStatus::Cancelled : StepStatus::Failed,
                    'error_message' => $e->getMessage(),
                    'finished_at' => Carbon::now(),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);

                throw $e;
            } catch (Throwable $e) {
                $message = $this->safeMessage($e);

                $step->update([
                    'status' => StepStatus::Failed,
                    'error_message' => $message,
                    'finished_at' => Carbon::now(),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);

                if ($attempt <= $retries) {
                    usleep(min(30000, $backoff * (2 ** ($attempt - 1))) * 1000);

                    continue;
                }

                $context->recordNode($key, 'failed', $message);

                if ($policy === 'continue') {
                    $context->hadFailures = true;
                    $context->log('error', "Node \"{$name}\" failed and was skipped (continue policy): {$message}", [], $key);

                    return null;
                }

                throw new WorkflowFailedException("Node \"{$name}\" failed: {$message}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rawConfig
     * @return array<string, mixed>
     */
    private function resolveConfig(NodeType $nodeType, array $rawConfig, ExecutionContext $context): array
    {
        $scope = $context->scope();
        $noResolve = [];
        foreach ($nodeType->configSchema() as $field) {
            if (($field['resolve'] ?? true) === false) {
                $noResolve[$field['key']] = true;
            }
        }

        $config = [];
        foreach ($rawConfig as $key => $value) {
            $config[$key] = isset($noResolve[$key]) ? $value : $this->resolver->resolve($value, $scope);
        }

        return $config;
    }

    /**
     * @return list<string>
     */
    private function activeSources(Graph $graph, string $key, RunFrame $frame): array
    {
        $sources = [];
        foreach ($graph->incoming[$key] as $edge) {
            if (($frame->decided[$edge['source']] ?? null) === 'executed'
                && in_array($edge['sourceHandle'], $frame->handles[$edge['source']] ?? [], true)
                && ! in_array($edge['source'], $sources, true)) {
                $sources[] = $edge['source'];
            }
        }

        return $sources;
    }

    private function viaEach(Graph $graph, string $target, string $source): bool
    {
        foreach ($graph->incoming[$target] as $edge) {
            if ($edge['source'] === $source && $edge['sourceHandle'] === 'each') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function handlesFor(Graph $graph, string $key): array
    {
        $node = $graph->nodes[$key];
        $type = $this->registry->get((string) $node['type']);
        $config = array_merge($type instanceof AbstractNode ? $type->defaults() : [], (array) ($node['config'] ?? []));

        return $type->outputHandles($config);
    }

    private function recordSkipped(Graph $graph, string $key, ExecutionContext $context): void
    {
        $node = $graph->nodes[$key];

        WorkflowExecutionStep::create([
            'execution_id' => $context->execution->id,
            'node_key' => $key,
            'node_type' => (string) $node['type'],
            'node_name' => (string) ($node['name'] ?? $node['type']),
            'status' => StepStatus::Skipped,
            'sequence' => ++$context->sequence,
        ]);
    }

    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof NodeExecutionException || $e instanceof ExpressionException || $e instanceof InvalidArgumentException) {
            return $e->getMessage();
        }

        report($e);

        if ($e instanceof QueryException) {
            return 'Database error while executing the node (details in the application log).';
        }

        return class_basename($e).': '.mb_substr($e->getMessage(), 0, 300);
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionFor(WorkflowExecution $execution): array
    {
        if ($execution->workflow_version) {
            $version = WorkflowVersion::query()
                ->where('workflow_id', $execution->workflow_id)
                ->where('version', $execution->workflow_version)
                ->value('definition');

            if ($version) {
                return is_array($version) ? $version : json_decode($version, true);
            }
        }

        return $execution->workflow->definition;
    }

    /**
     * @return array<string, array{value: string|null, secret: bool}>
     */
    private function globalVariables(?int $workspaceId): array
    {
        return WorkflowVariable::query()
            ->where(fn ($q) => $q->whereNull('workspace_id')->when($workspaceId, fn ($q) => $q->orWhere('workspace_id', $workspaceId)))
            ->orderByRaw('workspace_id is null desc') // workspace values override global ones
            ->get()
            ->mapWithKeys(fn (WorkflowVariable $v) => [$v->key => ['value' => $v->value, 'secret' => $v->is_secret]])
            ->all();
    }

    /**
     * Seed state for "execute from node": upstream outputs come from a previous
     * execution, the selected node and its descendants run again.
     *
     * @param  array<string, mixed>  $state
     */
    private function preparePartialRun(Graph $graph, ExecutionContext $context, RunFrame $frame, string $startKey, array $state): void
    {
        $seed = (array) ($state['seed_node_states'] ?? []);
        $context->nodeStates = $seed;
        $context->variables = array_merge($context->variables, (array) ($state['seed_variables'] ?? []));
        $rerun = $graph->reachableFrom($startKey) + [$startKey => true];

        foreach ($graph->orderedScope() as $key) {
            if (isset($rerun[$key])) {
                continue;
            }
            if (($seed[$key]['status'] ?? null) === 'success') {
                $frame->decided[$key] = 'executed';
                $frame->handles[$key] = array_values(array_map('strval', (array) ($seed[$key]['handles'] ?? [])));
            } else {
                $frame->decided[$key] = 'skipped';
            }
        }

        $frame->forceStart = $startKey;
    }

    private function park(WorkflowExecution $execution, ExecutionContext $context, RunFrame $frame, int $seconds): void
    {
        $resumeAt = now()->addSeconds($seconds);

        $execution->transitionTo(ExecutionStatus::Waiting, [
            'resume_at' => $resumeAt,
            'node_executions' => $context->nodeExecutions,
            'state' => [
                'resume' => true,
                'node_states' => $context->nodeStates,
                'variables' => $context->variables,
                'decided' => $frame->decided,
                'handles' => $frame->handles,
                'node_executions' => $context->nodeExecutions,
                'sequence' => $context->sequence,
                'return_output' => $context->returnOutput,
                'had_failures' => $context->hadFailures,
            ],
        ]);

        RunWorkflowJob::dispatch($execution->id, RunWorkflowJob::timeoutFor($context->settings))
            ->delay($resumeAt)
            ->onQueue(config('workflows.queue'));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function restore(ExecutionContext $context, RunFrame $frame, array $state): void
    {
        $context->nodeStates = (array) ($state['node_states'] ?? []);
        $context->variables = array_merge($context->variables, (array) ($state['variables'] ?? []));
        $context->nodeExecutions = (int) ($state['node_executions'] ?? 0);
        $context->sequence = (int) ($state['sequence'] ?? 0);
        $context->returnOutput = $state['return_output'] ?? null;
        $context->hadFailures = (bool) ($state['had_failures'] ?? false);
        $frame->decided = array_map('strval', (array) ($state['decided'] ?? []));
        $frame->handles = array_map(fn ($h) => array_values(array_map('strval', (array) $h)), (array) ($state['handles'] ?? []));
    }

    private function finish(WorkflowExecution $execution, ExecutionContext $context, ExecutionStatus $status, ?string $error): void
    {
        $execution->refresh();
        $finishedAt = now();

        $result = $context->returnOutput ?? [
            'nodes_executed' => $context->nodeExecutions,
            'nodes_succeeded' => count(array_filter($context->nodeStates, fn ($s) => ($s['status'] ?? null) === 'success')),
        ];

        // Snapshot for "execute from node" (outputs + handles only), capped in size.
        $snapshot = [
            'node_states' => array_map(fn ($s) => [
                'status' => $s['status'] ?? null,
                'output' => $s['output'] ?? null,
                'handles' => $s['handles'] ?? [],
            ], $context->nodeStates),
            'variables' => $context->variables,
        ];
        $snapshotJson = json_encode($snapshot);
        $stateToStore = $snapshotJson !== false && strlen($snapshotJson) <= (int) config('workflows.max_state_bytes', 524288)
            ? $snapshot
            : null;

        $execution->transitionTo($status, [
            'finished_at' => $finishedAt,
            'duration_ms' => $execution->started_at ? (int) $execution->started_at->diffInMilliseconds($finishedAt) : $context->elapsedMs(),
            'error_message' => $error,
            'result' => $this->redactor->forStorage($result, $context->secrets),
            'node_executions' => $context->nodeExecutions,
            'state' => $stateToStore,
            'cancel_requested_at' => $status === ExecutionStatus::Cancelled ? ($execution->cancel_requested_at ?? $finishedAt) : $execution->cancel_requested_at,
        ]);

        event(new WorkflowExecutionFinished($execution));
    }
}
