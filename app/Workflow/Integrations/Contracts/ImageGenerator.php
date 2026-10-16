<?php

namespace App\Workflow\Integrations\Contracts;

interface ImageGenerator
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function generate(string $prompt, array $options = []): array;
}
