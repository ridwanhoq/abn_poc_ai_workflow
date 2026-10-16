<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;

class MergeNode extends AbstractLogicNode
{
    protected string $icon = 'merge';

    public function type(): string
    {
        return 'logic.merge';
    }

    public function label(): string
    {
        return 'Merge';
    }

    public function description(): string
    {
        return 'Joins branches. Runs once all upstream branches have finished or been skipped.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('mode', 'Mode', 'select', ['default' => 'combine', 'expression' => false, 'options' => $this->options([
                'combine' => 'Combine objects (later wins)',
                'append' => 'Append into a list',
                'by_source' => 'Keyed by source node',
            ])]),
        ];
    }

    public function outputSchema(): array
    {
        return ['*' => 'Merged data', 'sources' => 'Keys of the branches that delivered data'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $inputs = (array) ($input->data['inputs'] ?? []);
        $mode = (string) $input->get('mode', 'combine');

        $merged = match ($mode) {
            'append' => ['items' => array_values($inputs)],
            'by_source' => $inputs,
            default => array_merge(...array_map(fn ($v) => is_array($v) ? $v : ['value' => $v], array_values($inputs) ?: [[]])),
        };

        return NodeResult::ok($merged + ['sources' => array_keys($inputs)]);
    }
}
