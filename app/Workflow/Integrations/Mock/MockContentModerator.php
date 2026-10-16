<?php

namespace App\Workflow\Integrations\Mock;

use App\Workflow\Integrations\Contracts\ContentModerator;

/**
 * Keyword rules over the supplied text plus deterministic sample warnings.
 *
 * TODO: Integrate a production moderation provider (e.g. AWS Rekognition Content
 * Moderation for video, OpenAI/Anthropic moderation for text). Request: transcript text and
 * keyframe images. Response: category scores with timestamps. Map provider categories to the
 * platform's maturity-rating policy and require human review below a confidence threshold.
 */
class MockContentModerator implements ContentModerator
{
    use Deterministic;

    private const RULES = [
        'violence' => ['fight', 'gun', 'blood', 'kill', 'weapon', 'behind us'],
        'language' => ['damn', 'hell'],
        'mature_themes' => ['secret', 'betrayal', 'crime', 'heist'],
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function moderate(string $text, array $context = []): array
    {
        $seed = $this->seed($text, $context['asset_id'] ?? null);
        $haystack = mb_strtolower($text);
        $flags = [];

        foreach (self::RULES as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, $word)) {
                    $flags[$category] = ($flags[$category] ?? 0) + 1;
                }
            }
        }

        $warnings = [];
        foreach (array_keys($flags) as $i => $category) {
            $warnings[] = [
                'category' => $category,
                'timestamp' => gmdate('H:i:s', 120 + (($seed + $i * 97) % 3000)),
                'confidence' => round(0.6 + (($seed + $i) % 35) / 100, 2),
            ];
        }

        $rating = match (true) {
            ($flags['violence'] ?? 0) >= 2 => 'TV-MA',
            isset($flags['violence']) || isset($flags['language']) => 'TV-14',
            isset($flags['mature_themes']) => 'TV-PG',
            default => 'TV-G',
        };

        return $this->simulatedMeta('content moderation') + [
            'maturity_rating' => $rating,
            'flags' => $flags,
            'content_warnings' => $warnings,
            'requires_human_review' => $rating === 'TV-MA',
        ];
    }
}
