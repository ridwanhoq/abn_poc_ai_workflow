<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Engine\ConditionEvaluator;
use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;

class FilterNode extends AbstractLogicNode
{
    protected string $icon = 'filter';

    public function __construct(private readonly ConditionEvaluator $evaluator) {}

    public function type(): string
    {
        return 'logic.filter';
    }

    public function label(): string
    {
        return 'Filter';
    }

    public function description(): string
    {
        return 'Keeps only the list items matching the conditions. Use {{ item.field }} in conditions.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('items', 'Items (list)', 'text', ['required' => true, 'placeholder' => '{{ nodes.find_records.output.records }}']),
            $this->conditionsField(['resolve' => false, 'default' => [['left' => '{{ item.status }}', 'operator' => 'equals', 'right' => 'active']]]),
            $this->combinatorField(),
        ];
    }

    public function outputSchema(): array
    {
        return ['items' => 'Matching items', 'kept_count' => 'Number kept', 'removed_count' => 'Number removed'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $items = $input->get('items', []);
        if (! is_array($items) || ! array_is_list($items)) {
            throw new NodeExecutionException('Filter "items" must resolve to a list.');
        }

        $kept = [];
        foreach ($items as $index => $item) {
            $conditions = (array) $input->resolveRaw('conditions', ['item' => $item, 'index' => $index]);
            if ($this->evaluator->evaluateAll(ConditionEvaluator::normalize($conditions), (string) $input->get('combinator', 'all'))) {
                $kept[] = $item;
            }
        }

        return NodeResult::ok(['items' => $kept, 'kept_count' => count($kept), 'removed_count' => count($items) - count($kept)]);
    }
}
