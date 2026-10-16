<?php

namespace App\Workflow\Integrations\Contracts;

interface Translator
{
    /** @return array<string, mixed> */
    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): array;
}
