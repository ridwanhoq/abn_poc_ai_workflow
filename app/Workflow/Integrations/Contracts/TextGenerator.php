<?php

namespace App\Workflow\Integrations\Contracts;

interface TextGenerator
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generate(string $task, string $prompt, array $context = []): array;
}
