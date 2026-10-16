<?php

namespace App\Workflow\Integrations\Contracts;

interface ContentModerator
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function moderate(string $text, array $context = []): array;
}
