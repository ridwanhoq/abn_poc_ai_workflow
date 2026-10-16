<?php

namespace App\Workflow\Engine;

/**
 * Early exit from a scope: "stop" (Stop node) or "wait" (long Delay node).
 */
final class ScopeSignal
{
    public function __construct(public readonly string $type, public readonly ?int $seconds = null) {}
}
