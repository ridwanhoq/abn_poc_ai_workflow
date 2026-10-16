<?php

namespace App\Workflow;

use App\Workflow\Contracts\NodeType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Whitelist-based registry of node types. Node classes are only ever
 * instantiated from config('workflows.nodes'); a type string coming from a
 * workflow definition is only used as a lookup key.
 */
class NodeRegistry
{
    /** @var array<string, NodeType> */
    private array $nodes = [];

    /**
     * @param  list<class-string<NodeType>>  $classes
     */
    public function __construct(Container $container, array $classes)
    {
        foreach ($classes as $class) {
            $node = $container->make($class);
            if (! $node instanceof NodeType) {
                throw new InvalidArgumentException("{$class} must implement ".NodeType::class);
            }
            if (isset($this->nodes[$node->type()])) {
                throw new InvalidArgumentException("Duplicate node type {$node->type()}");
            }
            $this->nodes[$node->type()] = $node;
        }
    }

    public function has(string $type): bool
    {
        return isset($this->nodes[$type]);
    }

    public function get(string $type): NodeType
    {
        return $this->nodes[$type] ?? throw new InvalidArgumentException("Unknown node type \"{$type}\".");
    }

    /**
     * @return array<string, NodeType>
     */
    public function all(): array
    {
        return $this->nodes;
    }

    public function isTrigger(string $type): bool
    {
        return $this->has($type) && $this->get($type)->category() === 'trigger';
    }
}
