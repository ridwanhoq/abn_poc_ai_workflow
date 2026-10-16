<?php

namespace App\Workflow\Integrations\Contracts;

interface VideoProcessor
{
    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function metadata(array $asset): array;

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function analyzeFrames(array $asset, int $frameCount): array;

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function thumbnail(array $content, array $options): array;

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function storyboard(array $content, array $options): array;

    /**
     * @param  array<string, mixed>  $asset
     * @param  list<string>  $targetLanguages
     * @return array<string, mixed>
     */
    public function subtitles(array $asset, string $sourceLanguage, array $targetLanguages): array;
}
