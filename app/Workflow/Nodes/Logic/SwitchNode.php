<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Engine\ConditionEvaluator;
use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;

class SwitchNode extends AbstractLogicNode
{
    protected string $icon = 'git-fork';

    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    public function type(): string
    {
        return 'logic.switch';
    }

    public function label(): string
    {
        return 'Switch';
    }

    public function description(): string
    {
        return 'Routes to the first case whose value equals the input value, otherwise "default".';
    }

    public function configSchema(): array
    {
        return [
            $this->field('value', 'Value to compare', 'text', ['required' => true, 'placeholder' => '{{ trigger.department }}']),
            $this->field('cases', 'Cases', 'cases', ['required' => true, 'default' => [['value' => 'a'], ['value' => 'b']], 'help' => 'Each case becomes an output handle (case_0, case_1 …).']),
        ];
    }

    public function outputHandles(array $config): array
    {
        $count = is_array($config['cases'] ?? null) ? count($config['cases']) : 0;

        return [...array_map(fn ($i) => "case_{$i}", range(0, max(0, $count - 1))), 'default'];
    }

    public function toArray(): array
    {
        return ['dynamic_handles' => true] + parent::toArray();
    }

    public function outputSchema(): array
    {
        return ['value' => 'The compared value', 'matched_case' => 'Index of the matched case or null', 'input' => 'Upstream data'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $value = $input->get('value');

        foreach ((array) $input->get('cases', []) as $i => $case) {
            if ($this->evaluator->evaluate($value, 'equals', $case['value'] ?? null)) {
                return NodeResult::branch(['value' => $value, 'matched_case' => $i, 'input' => $input->data], ["case_{$i}"]);
            }
        }

        return NodeResult::branch(['value' => $value, 'matched_case' => null, 'input' => $input->data], ['default']);
    }
}
