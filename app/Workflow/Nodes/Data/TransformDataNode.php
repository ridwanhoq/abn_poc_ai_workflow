<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Nodes\AbstractNode;

class TransformDataNode extends AbstractNode
{
    protected string $icon = 'wand-sparkles';

    private const OPERATIONS = [
        'template' => 'Build object from template',
        'pick' => 'Pick fields (comma separated)',
        'pluck' => 'Pluck one field from each item',
        'count' => 'Count items',
        'sum' => 'Sum a numeric field',
        'unique' => 'Unique values',
        'sort' => 'Sort by field',
        'group_count' => 'Count items grouped by field',
        'flatten' => 'Flatten one level',
    ];

    public function type(): string
    {
        return 'data.transform';
    }

    public function label(): string
    {
        return 'Transform Data';
    }

    public function category(): string
    {
        return 'data';
    }

    public function description(): string
    {
        return 'Reshapes data with safe, predefined operations.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('operation', 'Operation', 'select', ['default' => 'template', 'expression' => false, 'options' => $this->options(self::OPERATIONS)]),
            $this->field('source', 'Source data', 'text', ['placeholder' => '{{ nodes.find_records.output.records }}']),
            $this->field('argument', 'Field / argument', 'text', ['placeholder' => 'email']),
            $this->field('template', 'Template', 'json', ['default' => ['summary' => '{{ trigger.title }}']]),
        ];
    }

    public function outputSchema(): array
    {
        return ['result' => 'Transformed value'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $operation = (string) $input->get('operation', 'template');
        $source = $input->get('source');
        $arg = (string) $input->get('argument', '');

        if ($operation === 'template') {
            return NodeResult::ok(['result' => $input->get('template', [])]);
        }

        if (! is_array($source)) {
            throw new NodeExecutionException('Source must resolve to a list or object for this operation.');
        }

        $list = array_is_list($source) ? $source : [$source];
        $field = fn ($row) => is_array($row) ? ($row[$arg] ?? null) : $row;

        $result = match ($operation) {
            'pick' => array_map(fn ($row) => array_intersect_key((array) $row, array_flip(array_map('trim', explode(',', $arg)))), $list),
            'pluck' => array_map($field, $list),
            'count' => count($list),
            'sum' => array_sum(array_map(fn ($row) => (float) $field($row), $list)),
            'unique' => array_values(array_unique(array_map(fn ($v) => is_scalar($v) ? $v : json_encode($v), $arg !== '' ? array_map($field, $list) : $list))),
            'sort' => collect($list)->sortBy($arg !== '' ? $arg : null)->values()->all(),
            'group_count' => collect($list)->countBy(fn ($row) => (string) $field($row))->all(),
            'flatten' => collect($list)->flatten(1)->all(),
            default => throw new NodeExecutionException("Unknown operation {$operation}."),
        };

        return NodeResult::ok(['result' => $result]);
    }
}
