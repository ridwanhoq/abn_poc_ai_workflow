<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;

class GetRecordNode extends AbstractDataNode
{
    protected string $icon = 'file-search';

    public function type(): string
    {
        return 'data.get_record';
    }

    public function label(): string
    {
        return 'Get Database Record';
    }

    public function description(): string
    {
        return 'Loads one record by ID (whitelisted columns only).';
    }

    public function configSchema(): array
    {
        return [
            $this->modelField(),
            $this->field('id', 'Record ID', 'text', ['required' => true, 'placeholder' => '{{ trigger.id }}']),
            $this->field('fail_if_missing', 'Fail when not found', 'boolean', ['default' => true, 'expression' => false]),
        ];
    }

    protected function rules(array $config): array
    {
        return $this->modelRules($config);
    }

    public function outputSchema(): array
    {
        return ['found' => 'Whether the record exists', 'record' => 'The record (readable columns)'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $model = (string) $input->get('model');
        $id = $input->get('id');
        $record = $id === null ? null : $this->data->find($model, $id);

        if ($record === null && $input->get('fail_if_missing', true)) {
            throw new NodeExecutionException("{$model} record #".json_encode($id).' was not found.');
        }

        return NodeResult::ok(['found' => $record !== null, 'record' => $record]);
    }
}
