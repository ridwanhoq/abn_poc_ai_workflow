<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Data\CursorStats;
use App\Workflow\Data\DataModelRegistry;
use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;

/**
 * Runs every node connected to the "each" handle once per item (with
 * {{ item }} / {{ index }} in scope), then continues through "done".
 * Accepts a list or a batched cursor produced by Find Database Records.
 */
class LoopNode extends AbstractLogicNode
{
    protected string $icon = 'repeat';

    public function __construct(private readonly DataModelRegistry $data) {}

    public function type(): string
    {
        return 'logic.loop';
    }

    public function label(): string
    {
        return 'Loop / Iterate';
    }

    public function description(): string
    {
        return 'Iterates over a list or a batched record cursor. "each" runs per item, "done" runs once afterwards.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('items', 'Items', 'text', ['required' => true, 'placeholder' => '{{ nodes.find_records.output }}', 'help' => 'A list, or the output of Find Database Records in batched mode.']),
            $this->field('max_iterations', 'Maximum iterations', 'number', ['default' => 1000, 'min' => 1, 'max' => 100000, 'expression' => false]),
        ];
    }

    public function outputHandles(array $config): array
    {
        return ['each', 'done'];
    }

    public function outputSchema(): array
    {
        return ['iterations' => 'Items processed', 'batches' => 'Database batches fetched (cursor mode)', 'total' => 'Items available'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $items = $input->get('items');
        $max = (int) $input->get('max_iterations', 1000);

        if (is_array($items) && isset($items['__cursor'])) {
            $stats = new CursorStats;
            $descriptor = (array) $items['__cursor'];

            return new NodeResult(
                ['total' => (int) ($items['total_count'] ?? 0), 'mode' => 'cursor', 'batch_size' => (int) ($descriptor['batch_size'] ?? 100), '_stats' => $stats],
                ['each'],
                items: $this->limit($this->data->cursor($descriptor, $stats), $max),
            );
        }

        if (is_array($items) && isset($items['records']) && is_array($items['records'])) {
            $items = $items['records'];
        }

        if ($items === null || $items === '') {
            $items = [];
        }

        if (! is_array($items)) {
            throw new NodeExecutionException('Loop "items" must resolve to a list.');
        }

        $list = array_is_list($items) ? $items : [$items];

        return NodeResult::iterate(['total' => count($list), 'mode' => 'list'], array_slice($list, 0, $max));
    }

    /**
     * @param  iterable<mixed>  $items
     * @return \Generator<int, mixed>
     */
    private function limit(iterable $items, int $max): \Generator
    {
        $i = 0;
        foreach ($items as $item) {
            if ($i++ >= $max) {
                return;
            }
            yield $item;
        }
    }
}
