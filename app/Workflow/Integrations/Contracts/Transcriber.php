<?php

namespace App\Workflow\Integrations\Contracts;

interface Transcriber
{
    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function transcribe(array $asset, string $language): array;
}
