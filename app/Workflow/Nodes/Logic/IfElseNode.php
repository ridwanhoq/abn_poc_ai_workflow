<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Engine\ConditionEvaluator;
use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;

class IfElseNode extends AbstractLogicNode
{
    protected string $icon = 'split';

    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    public function type(): string
    {
        return 'logic.if';
    }

    public function label(): string
    {
        return 'IF / Else';
    }

    public function description(): string
    {
        return 'Routes execution to the "true" or "false" branch.';
    }

    public function configSchema(): array
    {
        return [$this->conditionsField(), $this->combinatorField()];
    }

    public function outputHandles(array $config): array
    {
        return ['true', 'false'];
    }

    public function outputSchema(): array
    {
        return ['passed' => 'Whether the conditions matched', 'input' => 'The data received from the previous node'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $passed = $this->evaluator->evaluateAll(ConditionEvaluator::normalize($input->get('conditions', [])), (string) $input->get('combinator', 'all'));

        return NodeResult::branch(['passed' => $passed, 'input' => $input->data], [$passed ? 'true' : 'false']);
    }
}
