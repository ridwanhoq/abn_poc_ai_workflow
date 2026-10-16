<?php

namespace App\Workflow\Engine;

use App\Workflow\NodeRegistry;
use App\Workflow\Nodes\AbstractNode;

/**
 * Server-side validation of a workflow definition. Saving a draft is allowed
 * with errors (they are shown in the editor); activation and execution are not.
 */
class GraphValidator
{
    public const MAX_NODES = 200;

    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return array{valid: bool, errors: list<array{node: string|null, field: string|null, message: string}>, warnings: list<array{node: string|null, message: string}>}
     */
    public function validate(array $definition): array
    {
        $errors = [];
        $warnings = [];
        $error = function (string $message, ?string $node = null, ?string $field = null) use (&$errors) {
            $errors[] = ['node' => $node, 'field' => $field, 'message' => $message];
        };

        $nodes = $definition['nodes'] ?? null;
        $edges = $definition['edges'] ?? [];

        if (! is_array($nodes) || ! array_is_list($nodes) || ! is_array($edges) || ! array_is_list($edges)) {
            $error('Definition must contain "nodes" and "edges" lists.');

            return $this->report($errors, $warnings);
        }

        if ($nodes === []) {
            $error('Add at least a trigger node.');

            return $this->report($errors, $warnings);
        }

        if (count($nodes) > self::MAX_NODES) {
            $error('A workflow may contain at most '.self::MAX_NODES.' nodes.');
        }

        // ---- Nodes ---------------------------------------------------------
        $byKey = [];
        $triggers = [];

        foreach ($nodes as $i => $node) {
            $key = $node['key'] ?? null;
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key)) {
                $error('Node #'.($i + 1).' has an invalid key (lowercase letters, numbers, underscores; must start with a letter).');

                continue;
            }
            if (isset($byKey[$key])) {
                $error("Duplicate node key \"{$key}\".", $key);

                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if (! $this->registry->has($type)) {
                $error("Unknown node type \"{$type}\".", $key);

                continue;
            }
            if (isset($node['config']) && ! is_array($node['config'])) {
                $error('Node configuration must be an object.', $key);

                continue;
            }

            $byKey[$key] = $node;
            if ($this->registry->isTrigger($type)) {
                $triggers[] = $key;
            }

            $nodeType = $this->registry->get($type);
            $config = array_merge(method_exists($nodeType, 'defaults') ? $nodeType->defaults() : [], $node['config'] ?? []);
            foreach ($nodeType->validateConfig($config) as $field => $message) {
                $error($message, $key, (string) $field);
            }

            $settings = $node['settings'] ?? [];
            if (isset($settings['retries']) && (! is_numeric($settings['retries']) || $settings['retries'] < 0 || $settings['retries'] > 5)) {
                $error('Retries must be between 0 and 5.', $key, 'settings.retries');
            }
            if (isset($settings['on_error']) && ! in_array($settings['on_error'], ['inherit', 'stop', 'continue'], true)) {
                $error('Invalid error policy.', $key, 'settings.on_error');
            }
        }

        if (count($triggers) === 0) {
            $error('The workflow needs exactly one trigger node.');
        } elseif (count($triggers) > 1) {
            $error('Only one trigger node is allowed per workflow.', $triggers[1]);
        }

        // ---- Edges ---------------------------------------------------------
        $seen = [];
        foreach ($edges as $edge) {
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            $handle = (string) (($edge['sourceHandle'] ?? null) ?: 'main');

            if (! isset($byKey[$source]) || ! isset($byKey[$target])) {
                $error('A connection references a missing node.');

                continue;
            }
            if ($source === $target) {
                $error('A node cannot connect to itself.', $source);

                continue;
            }

            $sourceType = $this->registry->get($byKey[$source]['type']);
            $sourceConfig = array_merge($sourceType instanceof AbstractNode ? $sourceType->defaults() : [], $byKey[$source]['config'] ?? []);
            if (! in_array($handle, $sourceType->outputHandles($sourceConfig), true)) {
                $error("Output \"{$handle}\" does not exist on this node.", $source);
            }
            if (! $this->registry->get($byKey[$target]['type'])->acceptsInput()) {
                $error('Trigger nodes cannot have incoming connections.', $target);
            }

            $signature = "{$source}:{$handle}->{$target}";
            if (isset($seen[$signature])) {
                $warnings[] = ['node' => $source, 'message' => 'Duplicate connection ignored.'];
            }
            $seen[$signature] = true;
        }

        if ($errors !== []) {
            return $this->report($errors, $warnings);
        }

        // ---- Graph structure -------------------------------------------------
        $graph = new Graph($definition);

        if ($graph->topologicalOrder() === null) {
            $error('The workflow contains a cycle. Use a Loop node to repeat steps.');

            return $this->report($errors, $warnings);
        }

        $trigger = $triggers[0];
        $reachable = $graph->reachableFrom($trigger) + [$trigger => true];
        foreach (array_keys($byKey) as $key) {
            if (! isset($reachable[$key])) {
                $error('This node is not connected to the trigger.', $key);
            }
        }

        $inlineMax = (int) config('workflows.inline_delay_max_seconds', 5);
        foreach ($graph->loopKeys() as $loop) {
            $body = $graph->loopBody($loop);
            $after = $graph->reachableFrom($loop, 'done');

            if ($body === []) {
                $warnings[] = ['node' => $loop, 'message' => 'Nothing is connected to the "each" output.'];
            }
            foreach (array_keys(array_intersect_key($body, $after)) as $key) {
                $error('Nodes cannot be part of both the "each" and "done" paths of a loop.', $key);
            }
            foreach (array_keys($body) as $key) {
                foreach ($graph->incoming[$key] as $edge) {
                    if ($edge['source'] !== $loop && ! isset($body[$edge['source']])) {
                        $error('Nodes inside a loop can only receive connections from the loop or other nodes inside it.', $key);
                        break;
                    }
                }
                if ($graph->typeOf($key) === 'logic.delay' && (int) ($graph->nodes[$key]['config']['seconds'] ?? 0) > $inlineMax) {
                    $error("Delays inside a loop are limited to {$inlineMax} seconds.", $key, 'seconds');
                }
            }
        }

        return $this->report($errors, $warnings);
    }

    /**
     * @param  list<array{node: string|null, field: string|null, message: string}>  $errors
     * @param  list<array{node: string|null, message: string}>  $warnings
     * @return array{valid: bool, errors: list<array{node: string|null, field: string|null, message: string}>, warnings: list<array{node: string|null, message: string}>}
     */
    private function report(array $errors, array $warnings): array
    {
        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }
}
