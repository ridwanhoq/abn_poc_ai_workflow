<?php

namespace App\Workflow\Engine;

/**
 * Value returned by a node's execute() method.
 */
final class NodeResult
{
    /** Step record created by the engine for this result (set by the engine). */
    public ?int $stepId = null;

    /**
     * @param  array<string, mixed>  $output
     * @param  list<string>|null  $handles  active output handles; null = every handle of the node
     */
    public function __construct(
        public array $output,
        public ?array $handles = null,
        public bool $stop = false,
        public ?string $stopStatus = null,
        public ?int $waitSeconds = null,
        /** @var iterable<mixed>|null items for loop nodes */
        public ?iterable $items = null,
    ) {}

    /** @param array<string, mixed> $output */
    public static function ok(array $output): self
    {
        return new self($output);
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  list<string>  $handles
     */
    public static function branch(array $output, array $handles): self
    {
        return new self($output, $handles);
    }

    /** @param array<string, mixed> $output */
    public static function stop(array $output, string $status = 'success'): self
    {
        return new self($output, [], true, $status);
    }

    /** @param array<string, mixed> $output */
    public static function wait(array $output, int $seconds): self
    {
        return new self($output, null, false, null, $seconds);
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  iterable<mixed>  $items
     */
    public static function iterate(array $output, iterable $items): self
    {
        return new self($output, ['each'], false, null, null, $items);
    }
}
