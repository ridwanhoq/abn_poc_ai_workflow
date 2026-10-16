<?php

namespace App\Workflow\Engine;

/**
 * Per-scope bookkeeping (top level, or one loop iteration).
 */
final class RunFrame
{
    /** @var array<string, string> key => executed | skipped | failed */
    public array $decided = [];

    /** @var array<string, list<string>> key => active output handles */
    public array $handles = [];

    /** Node forced to run without active inputs ("execute from node"). */
    public ?string $forceStart = null;
}
