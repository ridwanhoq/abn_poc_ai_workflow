<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use Illuminate\Support\Facades\DB;

class CreateRecordNode extends AbstractDataNode
{
    protected string $icon = 'database-plus';

    public function type(): string
    {
        return 'data.create_record';
    }

    public function label(): string
    {
        return 'Create Database Record';
    }

    public function description(): string
    {
        return 'Creates one record, or one per item of a list, in a whitelisted table (transactional).';
    }

    public function configSchema(): array
    {
        return [
            $this->modelField(),
            $this->field('mode', 'Mode', 'select', ['default' => 'single', 'expression' => false, 'options' => $this->options([
                'single' => 'Single record',
                'many' => 'One record per item',
            ])]),
            $this->field('items', 'Items (many mode)', 'text', ['placeholder' => '{{ nodes.subtitles.output.tracks }}', 'help' => 'Fields can reference {{ item.x }} in "many" mode.']),
            $this->field('fields', 'Fields', 'keyvalue', ['required' => true, 'default' => [], 'resolve' => false]),
        ];
    }

    protected function rules(array $config): array
    {
        $errors = $this->modelRules($config) + $this->columnRules($config, 'fields', 'writable');

        if (($config['mode'] ?? 'single') === 'many' && empty($config['items'])) {
            $errors['items'] = 'Items are required in "many" mode.';
        }

        return $errors;
    }

    public function outputSchema(): array
    {
        return ['record' => 'Created record (single)', 'ids' => 'IDs of created records', 'created_count' => 'How many were created'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $model = (string) $input->get('model');

        if ($input->get('mode', 'single') !== 'many') {
            $created = $this->data->create($model, (array) $input->resolveRaw('fields'));

            return NodeResult::ok([
                'record' => $this->data->serialize($model, $created->refresh()),
                'ids' => [$created->getKey()],
                'created_count' => 1,
            ]);
        }

        $items = $input->get('items', []);
        if (! is_array($items) || ! array_is_list($items)) {
            throw new NodeExecutionException('"items" must resolve to a list in many mode.');
        }

        $ids = DB::transaction(function () use ($items, $input, $model) {
            $ids = [];
            foreach ($items as $index => $item) {
                $fields = (array) $input->resolveRaw('fields', ['item' => $item, 'index' => $index]);
                $ids[] = $this->data->create($model, $fields)->getKey();
            }

            return $ids;
        });

        return NodeResult::ok(['ids' => $ids, 'created_count' => count($ids)]);
    }
}
