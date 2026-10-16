<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;

class UpdateRecordNode extends AbstractDataNode
{
    protected string $icon = 'database-backup';

    public function type(): string
    {
        return 'data.update_record';
    }

    public function label(): string
    {
        return 'Update Database Record';
    }

    public function description(): string
    {
        return 'Updates whitelisted columns of one record.';
    }

    public function configSchema(): array
    {
        return [
            $this->modelField(),
            $this->field('id', 'Record ID', 'text', ['required' => true]),
            $this->field('fields', 'Fields', 'keyvalue', ['required' => true, 'default' => []]),
        ];
    }

    protected function rules(array $config): array
    {
        return $this->modelRules($config) + $this->columnRules($config, 'fields', 'updatable');
    }

    public function outputSchema(): array
    {
        return ['record' => 'The updated record', 'changed' => 'Columns that changed'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $model = (string) $input->get('model');
        $record = $this->data->update($model, $input->get('id'), (array) $input->get('fields', []));

        return NodeResult::ok(['record' => $this->data->serialize($model, $record), 'changed' => array_keys($record->getChanges())]);
    }
}
