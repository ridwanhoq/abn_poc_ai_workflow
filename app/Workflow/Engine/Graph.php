<?php

namespace App\Workflow\Engine;

/**
 * Read-only graph view of a workflow definition.
 */
class Graph
{
    /** @var array<string, array<string, mixed>> */
    public array $nodes = [];

    /** @var array<string, list<array{source: string, sourceHandle: string, target: string}>> */
    public array $outgoing = [];

    /** @var array<string, list<array{source: string, sourceHandle: string, target: string}>> */
    public array $incoming = [];

    /** @var list<string>|null */
    private ?array $order = null;

    /** @var array<string, array<string, true>> */
    private array $bodies = [];

    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(array $definition)
    {
        foreach ($definition['nodes'] ?? [] as $node) {
            if (isset($node['key']) && is_string($node['key'])) {
                $this->nodes[$node['key']] = $node;
                $this->outgoing[$node['key']] = [];
                $this->incoming[$node['key']] = [];
            }
        }

        foreach ($definition['edges'] ?? [] as $edge) {
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            if (! isset($this->nodes[$source], $this->nodes[$target])) {
                continue;
            }
            $normalized = [
                'source' => $source,
                'sourceHandle' => (string) (($edge['sourceHandle'] ?? null) ?: 'main'),
                'target' => $target,
            ];
            $this->outgoing[$source][] = $normalized;
            $this->incoming[$target][] = $normalized;
        }
    }

    /**
     * Kahn topological sort. Returns null when the graph contains a cycle.
     * Ties keep definition order, so execution order is deterministic.
     *
     * @return list<string>|null
     */
    public function topologicalOrder(): ?array
    {
        if ($this->order !== null) {
            return $this->order;
        }

        $inDegree = array_map(fn ($edges) => count($edges), $this->incoming);
        $queue = array_keys(array_filter($inDegree, fn ($d) => $d === 0));
        $order = [];

        while ($queue !== []) {
            $key = array_shift($queue);
            $order[] = $key;
            foreach ($this->outgoing[$key] as $edge) {
                if (--$inDegree[$edge['target']] === 0) {
                    $queue[] = $edge['target'];
                }
            }
        }

        return count($order) === count($this->nodes) ? $this->order = $order : null;
    }

    /**
     * Nodes reachable from $key (optionally only through one output handle). Excludes $key.
     *
     * @return array<string, true>
     */
    public function reachableFrom(string $key, ?string $handle = null): array
    {
        $seen = [];
        $stack = [];

        foreach ($this->outgoing[$key] ?? [] as $edge) {
            if ($handle === null || $edge['sourceHandle'] === $handle) {
                $stack[] = $edge['target'];
            }
        }

        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($seen[$current]) || $current === $key) {
                continue;
            }
            $seen[$current] = true;
            foreach ($this->outgoing[$current] as $edge) {
                $stack[] = $edge['target'];
            }
        }

        return $seen;
    }

    public function typeOf(string $key): string
    {
        return (string) ($this->nodes[$key]['type'] ?? '');
    }

    /**
     * @return list<string>
     */
    public function loopKeys(): array
    {
        return array_values(array_filter(array_keys($this->nodes), fn ($k) => $this->typeOf($k) === 'logic.loop'));
    }

    /**
     * Body of a loop node = everything reachable from its "each" handle.
     *
     * @return array<string, true>
     */
    public function loopBody(string $loopKey): array
    {
        return $this->bodies[$loopKey] ??= $this->reachableFrom($loopKey, 'each');
    }

    /**
     * Ordered keys to execute directly within a scope (top level or a loop body),
     * excluding the bodies of nested loops, which their loop node runs itself.
     *
     * @param  array<string, true>|null  $within  null = whole graph
     * @return list<string>
     */
    public function orderedScope(?array $within = null): array
    {
        $order = $this->topologicalOrder() ?? [];
        $candidates = $within === null ? array_fill_keys($order, true) : $within;

        foreach ($this->loopKeys() as $loop) {
            if (isset($candidates[$loop])) {
                foreach ($this->loopBody($loop) as $bodyKey => $_) {
                    unset($candidates[$bodyKey]);
                }
            }
        }

        return array_values(array_filter($order, fn ($k) => isset($candidates[$k])));
    }

    /**
     * Loop that directly owns $key, if any.
     */
    public function enclosingLoop(string $key): ?string
    {
        foreach ($this->loopKeys() as $loop) {
            if (isset($this->loopBody($loop)[$key])) {
                return $loop;
            }
        }

        return null;
    }
}
