<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Data\DataModelRegistry;
use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;

class FindRecordsNode extends AbstractDataNode
{
    protected string $icon = 'database-search';

    public function type(): string
    {
        return 'data.find_records';
    }

    public function label(): string
    {
        return 'Find Database Records';
    }

    public function description(): string
    {
        return 'Queries a whitelisted model with filters. "Batched" mode streams results to a Loop in chunks.';
    }

    public function configSchema(): array
    {
        return [
            $this->modelField(),
            $this->field('filters', 'Filters', 'filters', [
                'default' => [],
                'operators' => DataModelRegistry::OPERATORS,
                'help' => 'All filters are combined with AND and use bound parameters.',
            ]),
            $this->field('mode', 'Result mode', 'select', ['default' => 'list', 'expression' => false, 'options' => $this->options([
                'list' => 'List (up to limit)',
                'cursor' => 'Batched cursor for Loop (chunkById)',
            ])]),
            $this->field('limit', 'Limit (list mode)', 'number', ['default' => 100, 'min' => 1, 'max' => 1000, 'expression' => false]),
            $this->field('batch_size', 'Batch size (batched mode)', 'number', ['default' => 100, 'min' => 1, 'max' => 1000, 'expression' => false]),
            $this->field('order_by', 'Order by', 'text', ['default' => 'id', 'expression' => false]),
            $this->field('direction', 'Direction', 'select', ['default' => 'asc', 'expression' => false, 'options' => $this->options(['asc' => 'Ascending', 'desc' => 'Descending'])]),
        ];
    }

    protected function rules(array $config): array
    {
        $errors = $this->modelRules($config);
        $model = (string) ($config['model'] ?? '');

        if ($errors === [] && $this->data->exists($model)) {
            $readable = $this->data->definition($model)['readable'];
            foreach ((array) ($config['filters'] ?? []) as $i => $filter) {
                if (! in_array($filter['column'] ?? null, $readable, true)) {
                    $errors['filters'] = 'Filter #'.($i + 1).': column "'.($filter['column'] ?? '').'" is not available on '.$model.'.';
                    break;
                }
                if (! array_key_exists((string) ($filter['operator'] ?? ''), DataModelRegistry::OPERATORS)) {
                    $errors['filters'] = 'Filter #'.($i + 1).': unsupported operator.';
                    break;
                }
            }
            if (! empty($config['order_by']) && ! in_array($config['order_by'], $readable, true)) {
                $errors['order_by'] = 'Order column is not available on '.$model.'.';
            }
        }

        return $errors;
    }

    public function outputSchema(): array
    {
        return [
            'records' => 'Matching records (list mode)',
            'count' => 'Number of records returned (list mode)',
            'total_count' => 'Total matching records',
            '__cursor' => 'Batched query descriptor consumed by Loop (cursor mode)',
        ];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $model = (string) $input->get('model');
        $filters = array_values((array) $input->get('filters', []));
        $query = $this->data->filtered($model, $filters);
        $total = (clone $query)->count();

        if ($input->get('mode', 'list') === 'cursor') {
            return NodeResult::ok([
                'mode' => 'cursor',
                'total_count' => $total,
                'batch_size' => (int) $input->get('batch_size', 100),
                '__cursor' => ['model' => $model, 'filters' => $filters, 'batch_size' => (int) $input->get('batch_size', 100)],
            ]);
        }

        $orderBy = (string) $input->get('order_by', 'id');
        if (! in_array($orderBy, $this->data->definition($model)['readable'], true)) {
            throw new NodeExecutionException("Cannot order by {$orderBy}.");
        }

        $records = $query
            ->orderBy($orderBy, $input->get('direction') === 'desc' ? 'desc' : 'asc')
            ->limit((int) $input->get('limit', 100))
            ->get()
            ->map(fn ($row) => $this->data->serialize($model, $row))
            ->all();

        return NodeResult::ok(['mode' => 'list', 'records' => $records, 'count' => count($records), 'total_count' => $total]);
    }
}
