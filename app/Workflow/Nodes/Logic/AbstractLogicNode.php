<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Engine\ConditionEvaluator;
use App\Workflow\Nodes\AbstractNode;

abstract class AbstractLogicNode extends AbstractNode
{
    public function category(): string
    {
        return 'logic';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function conditionsField(array $extra = []): array
    {
        return $this->field('conditions', 'Conditions', 'conditions', array_merge([
            'required' => true,
            'default' => [['left' => '', 'operator' => 'equals', 'right' => '']],
            'operators' => ConditionEvaluator::OPERATORS,
            'unary' => ConditionEvaluator::UNARY,
        ], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    protected function combinatorField(): array
    {
        return $this->field('combinator', 'Match', 'select', [
            'default' => 'all',
            'options' => $this->options(['all' => 'All conditions (AND)', 'any' => 'Any condition (OR)']),
            'expression' => false,
        ]);
    }
}
