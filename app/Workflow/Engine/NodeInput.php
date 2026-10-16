<?php

namespace App\Workflow\Engine;

/**
 * Everything a node needs while executing.
 */
final class NodeInput
{
    /**
     * @param  array<string, mixed>  $node  definition entry (key, type, name, config, settings)
     * @param  array<string, mixed>  $config  configuration with expressions resolved (except non-resolving fields)
     * @param  array<string, mixed>  $rawConfig  configuration as stored
     * @param  mixed  $data  output of the upstream node(s)
     */
    public function __construct(
        public readonly array $node,
        public readonly array $config,
        public readonly array $rawConfig,
        public readonly mixed $data,
        public readonly ExecutionContext $context,
        public readonly ExpressionResolver $resolver,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->config[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * Resolve a raw (non auto-resolved) config value with extra scope, e.g. per loop item.
     *
     * @param  array<string, mixed>  $extraScope
     */
    public function resolveRaw(string $key, array $extraScope = []): mixed
    {
        return $this->resolver->resolve($this->rawConfig[$key] ?? null, array_merge($this->context->scope(), $extraScope));
    }

    public function key(): string
    {
        return (string) $this->node['key'];
    }
}
