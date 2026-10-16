<?php

namespace App\Workflow\Integrations\Mock;

use App\Workflow\Integrations\Contracts\ImageGenerator;

/**
 * Returns an image *specification* only. No image file is generated, so no
 * downloadable asset is exposed.
 *
 * TODO: Integrate a production image-generation provider (e.g. Stability AI, OpenAI Images,
 * Google Imagen). Credentials: API key in workflow_integrations (encrypted). Request: prompt,
 * negative prompt, aspect ratio, resolution, seed, brand template. Response: image bytes or URL —
 * persist to object storage (S3) and return the storage key. Handle content-policy rejections,
 * 429 rate limits and timeouts (≤60s).
 */
class MockImageGenerator implements ImageGenerator
{
    use Deterministic;

    public function generate(string $prompt, array $options = []): array
    {
        $seed = $this->seed($prompt, $options);

        return $this->simulatedMeta('image generation') + [
            'model' => 'mock-image-1',
            'image_url' => null,
            'file_generated' => false,
            'prompt' => $prompt,
            'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
            'resolution' => $options['resolution'] ?? '1920x1080',
            'style' => $options['style'] ?? 'cinematic',
            'dominant_colors' => $this->pickMany(['#1f2a44', '#e85d3f', '#f4c95d', '#3fa7a3', '#6a4c93', '#f1f1f1', '#0b0b0f'], $seed, 3),
            'composition' => $this->pick(['subject left, title space right', 'centered subject, vignette', 'low-angle hero shot'], $seed),
            'seed' => $seed % 100000,
        ];
    }
}
