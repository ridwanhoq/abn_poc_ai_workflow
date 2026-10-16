<?php

namespace App\Workflow\Data;

/**
 * Mutable counter shared between a batched cursor and the Loop node.
 */
final class CursorStats
{
    public int $batches = 0;
}
