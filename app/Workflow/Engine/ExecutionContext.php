<?php

namespace App\Workflow\Engine;

use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowExecutionLog;
use App\Workflow\Exceptions\ExecutionCancelledException;
use App\Workflow\Exceptions\WorkflowFailedException;
use App\Workflow\Support\Redactor;

/**
 * Mutable state shared by every node of one execution: trigger payload,
 * node outputs, variables, loop scope, limits and cancellation state.
 */
class ExecutionContext
{
    /** @var array<string, array<string, mixed>> */
    public array $nodeStates = [];

    /** @var array<string, mixed> */
    public array $variables = [];

    /** @var list<array{item: mixed, index: int}> */
    private array $loopStack = [];

    /** @var array<string, mixed>|null */
    public ?array $returnOutput = null;

    public int $nodeExecutions = 0;

    public int $sequence = 0;

    public bool $hadFailures = false;

    public ?string $currentNodeKey = null;

    /** @var list<string> values of secret variables, masked in everything that is stored or logged */
    public array $secrets = [];

    private float $startedAt;

    private float $lastCancelCheck = 0.0;

    /**
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>  $settings
     * @param  list<int>  $callStack  workflow ids of parent executions (subworkflow recursion guard)
     */
    public function __construct(
        public readonly WorkflowExecution $execution,
        public readonly Workflow $workflow,
        public array $trigger,
        public readonly array $settings,
        public readonly array $callStack = [],
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * Data visible to expressions.
     *
     * @return array<string, mixed>
     */
    public function scope(): array
    {
        $loop = end($this->loopStack) ?: null;

        return [
            'trigger' => $this->trigger,
            'nodes' => $this->nodeStates,
            'variables' => $this->variables,
            'item' => $loop['item'] ?? null,
            'index' => $loop['index'] ?? null,
            'execution' => [
                'id' => $this->execution->id,
                'uuid' => $this->execution->uuid,
                'mode' => $this->execution->mode,
                'trigger_type' => $this->execution->trigger_type,
            ],
            'workflow' => [
                'id' => $this->workflow->id,
                'name' => $this->workflow->name,
            ],
        ];
    }

    /**
     * @param  list<string>  $handles
     */
    public function recordNode(string $key, string $status, mixed $output, array $handles = []): void
    {
        $state = ($this->nodeStates[$key] ?? []) + ['runs' => 0, 'success_count' => 0, 'error_count' => 0];
        $state['status'] = $status;
        $state['runs']++;

        if ($status === 'success') {
            $state['success_count']++;
            $state['output'] = $output;
            $state['handles'] = $handles;
        } else {
            $state['error_count']++;
            $state['error'] = $output;
            $state['output'] ??= null;
        }

        $this->nodeStates[$key] = $state;
    }

    public function pushItem(mixed $item, int $index): void
    {
        $this->loopStack[] = ['item' => $item, 'index' => $index];
    }

    public function popItem(): void
    {
        array_pop($this->loopStack);
    }

    public function inLoop(): bool
    {
        return $this->loopStack !== [];
    }

    public function currentIteration(): ?int
    {
        $loop = end($this->loopStack);

        return $loop ? $loop['index'] : null;
    }

    public function isTestRun(): bool
    {
        return $this->execution->mode === 'test';
    }

    public function demoMode(): bool
    {
        return (bool) config('workflows.demo_mode', true);
    }

    /**
     * Real external side effects (email delivery, outbound HTTP) require demo
     * mode off, a production (non-test) run and the per-workflow opt-in.
     */
    public function allowsExternalSideEffects(): bool
    {
        return ! $this->demoMode()
            && ! $this->isTestRun()
            && (bool) ($this->settings['allow_external_side_effects'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = [], ?string $nodeKey = null): void
    {
        WorkflowExecutionLog::create([
            'execution_id' => $this->execution->id,
            'node_key' => $nodeKey ?? $this->currentNodeKey,
            'level' => $level,
            'message' => mb_substr((string) app(Redactor::class)->maskSecrets($message, $this->secrets), 0, 1000),
            'context' => app(Redactor::class)->forStorage($context, $this->secrets),
        ]);
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    /**
     * Enforce timeout, node-execution limit and cooperative cancellation.
     */
    public function guard(): void
    {
        $timeout = (int) ($this->settings['timeout_seconds'] ?? 300);
        if ($timeout > 0 && $this->elapsedMs() > $timeout * 1000) {
            throw new WorkflowFailedException("Execution timed out after {$timeout} seconds.");
        }

        $max = (int) ($this->settings['max_node_executions'] ?? 1000);
        if ($this->nodeExecutions >= $max) {
            throw new WorkflowFailedException("Maximum node executions ({$max}) exceeded. Increase the limit in workflow settings or reduce the loop size.");
        }

        if (microtime(true) - $this->lastCancelCheck >= 1.0) {
            $this->lastCancelCheck = microtime(true);
            $cancelRequested = WorkflowExecution::query()->whereKey($this->execution->id)->value('cancel_requested_at');
            if ($cancelRequested !== null) {
                throw new ExecutionCancelledException('Execution cancelled by user.');
            }
        }
    }
}
