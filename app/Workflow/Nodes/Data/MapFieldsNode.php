<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Nodes\AbstractNode;

class MapFieldsNode extends AbstractNode
{
    protected string $icon = 'arrow-right-left';

    public function type(): string
    {
        return 'data.map_fields';
    }

    public function label(): string
    {
        return 'Map Fields';
    }

    public function category(): string
    {
        return 'data';
    }

    public function description(): string
    {
        return 'Builds a new object (or one per list item) from field mappings.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('items', 'Items (optional list)', 'text', ['help' => 'When set, mappings run per item with {{ item }} in scope.']),
            $this->field('mappings', 'Mappings (target ← source)', 'keyvalue', ['required' => true, 'default' => ['name' => '{{ trigger.name }}'], 'resolve' => false]),
        ];
    }

    public function outputSchema(): array
    {
        return ['result' => 'Mapped object (single mode)', 'items' => 'Mapped items (list mode)'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $items = $input->get('items');

        if ($items === null) {
            return NodeResult::ok(['result' => (array) $input->resolveRaw('mappings')]);
        }

        if (! is_array($items) || ! array_is_list($items)) {
            throw new NodeExecutionException('"items" must resolve to a list.');
        }

        $mapped = [];
        foreach ($items as $index => $item) {
            $mapped[] = (array) $input->resolveRaw('mappings', ['item' => $item, 'index' => $index]);
        }

        return NodeResult::ok(['items' => $mapped, 'count' => count($mapped)]);
    }
}
