<?php

namespace Tests\Unit\Workflow;

use App\Workflow\Engine\Graph;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
    private function n(string $key, string $type = 'notify.log'): array
    {
        return ['key' => $key, 'type' => $type];
    }

    #[Test]
    public function it_orders_nodes_topologically_and_deterministically(): void
    {
        $graph = new Graph([
            'nodes' => [$this->n('d'), $this->n('b'), $this->n('a', 'trigger.manual'), $this->n('c')],
            'edges' => [
                ['source' => 'a', 'target' => 'b'],
                ['source' => 'a', 'target' => 'c'],
                ['source' => 'b', 'target' => 'd'],
                ['source' => 'c', 'target' => 'd'],
            ],
        ]);

        $order = $graph->topologicalOrder();
        $this->assertSame('a', $order[0]);
        $this->assertSame('d', $order[3]);
        $this->assertLessThan(array_search('d', $order), array_search('b', $order));
    }

    #[Test]
    public function it_detects_cycles(): void
    {
        $graph = new Graph([
            'nodes' => [$this->n('a'), $this->n('b')],
            'edges' => [['source' => 'a', 'target' => 'b'], ['source' => 'b', 'target' => 'a']],
        ]);

        $this->assertNull($graph->topologicalOrder());
    }

    #[Test]
    public function loop_bodies_are_excluded_from_the_outer_scope(): void
    {
        $graph = new Graph([
            'nodes' => [$this->n('t', 'trigger.manual'), $this->n('loop', 'logic.loop'), $this->n('body'), $this->n('after')],
            'edges' => [
                ['source' => 't', 'target' => 'loop'],
                ['source' => 'loop', 'target' => 'body', 'sourceHandle' => 'each'],
                ['source' => 'loop', 'target' => 'after', 'sourceHandle' => 'done'],
            ],
        ]);

        $this->assertSame(['t', 'loop', 'after'], $graph->orderedScope());
        $this->assertSame(['body'], $graph->orderedScope($graph->loopBody('loop')));
        $this->assertSame('loop', $graph->enclosingLoop('body'));
    }
}
