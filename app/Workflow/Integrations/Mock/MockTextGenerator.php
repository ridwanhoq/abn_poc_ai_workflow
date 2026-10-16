<?php

namespace App\Workflow\Integrations\Mock;

use App\Workflow\Integrations\Contracts\TextGenerator;
use Illuminate\Support\Str;

/**
 * Deterministic, template-based stand-in for a large language model.
 *
 * A production implementation exists: App\Workflow\Integrations\Anthropic\ClaudeTextGenerator,
 * selected by IntegrationManager::textGenerator() when the Anthropic integration is enabled.
 * TODO: Persist token usage per execution for cost reporting.
 */
class MockTextGenerator implements TextGenerator
{
    use Deterministic;

    public const TASKS = [
        'generic' => 'Free-form text',
        'summary' => 'Summarize',
        'media_metadata' => 'OTT metadata (summary, genres, keywords, tags)',
        'thumbnail_prompt' => 'Thumbnail image prompt',
        'retention_message' => 'Subscriber win-back message',
        'onboarding_checklist' => 'Role-specific onboarding checklist',
    ];

    private const GENRES = ['Drama', 'Thriller', 'Documentary', 'Comedy', 'Sci-Fi', 'Romance', 'Action', 'Family', 'Crime', 'Adventure'];

    private const KEYWORDS = ['family', 'journey', 'city lights', 'betrayal', 'friendship', 'ocean', 'technology', 'secrets', 'coming of age', 'rivalry', 'mountains', 'music', 'courtroom', 'heist', 'wildlife'];

    private const MOODS = ['uplifting', 'suspenseful', 'heartwarming', 'dark', 'whimsical', 'tense', 'reflective'];

    public function generate(string $task, string $prompt, array $context = []): array
    {
        $seed = $this->seed($task, $prompt, $context);
        $base = $this->simulatedMeta('AI text generation') + ['model' => 'mock-text-1', 'task' => $task];

        $result = match ($task) {
            'media_metadata' => $this->mediaMetadata($seed, $context),
            'thumbnail_prompt' => $this->thumbnailPrompt($seed, $context),
            'retention_message' => $this->retentionMessage($seed, $context),
            'onboarding_checklist' => $this->onboardingChecklist($context),
            'summary' => ['text' => 'Summary: '.Str::limit(trim(strip_tags($prompt)), 220)],
            default => ['text' => $this->generic($seed, $prompt)],
        };

        $text = $result['text'] ?? json_encode($result);

        return $base + $result + ['usage' => ['prompt_tokens' => (int) ceil(strlen($prompt) / 4), 'completion_tokens' => (int) ceil(strlen((string) $text) / 4)]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mediaMetadata(int $seed, array $context): array
    {
        $title = (string) ($context['title'] ?? 'Untitled asset');
        $genres = $this->pickMany(self::GENRES, $seed, 2);
        $keywords = $this->pickMany(self::KEYWORDS, $seed, 5);
        $mood = $this->pick(self::MOODS, $seed);
        $objects = $context['objects'] ?? [];

        $summary = sprintf(
            '"%s" is a %s %s story exploring %s and %s.%s',
            $title,
            $mood,
            strtolower($genres[0]),
            $keywords[0],
            $keywords[1],
            $objects ? ' Visual analysis highlights '.implode(', ', array_slice((array) $objects, 0, 3)).'.' : ''
        );

        return [
            'text' => $summary,
            'summary' => $summary,
            'genres' => $genres,
            'keywords' => $keywords,
            'tags' => array_values(array_unique([Str::slug($genres[0]), $mood, Str::slug($keywords[0]), 'ai-tagged'])),
            'mood' => $mood,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function thumbnailPrompt(int $seed, array $context): array
    {
        $title = (string) ($context['title'] ?? 'Untitled');
        $genre = (string) ($context['genre'] ?? $this->pick(self::GENRES, $seed));
        $mood = $this->pick(self::MOODS, $seed);
        $style = $this->pick(['cinematic key art', 'minimal poster', 'high-contrast photographic', 'painterly illustration'], $seed, 3);

        $prompt = "{$style} for the {$genre} title \"{$title}\", {$mood} mood, central subject in the left third, "
            .'clear negative space on the right for the title treatment, 16:9, no text, no logos';

        return ['text' => $prompt, 'prompt' => $prompt, 'style' => $style, 'mood' => $mood];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function retentionMessage(int $seed, array $context): array
    {
        $name = Str::before((string) ($context['name'] ?? 'there'), ' ');
        $genre = (string) ($context['favorite_genre'] ?? 'drama');
        $level = (string) ($context['risk_level'] ?? 'medium');
        $offer = $level === 'high' ? 'one month at 50% off' : 'a curated watchlist';
        $hook = $this->pick([
            "We saved a few new {$genre} titles we think you will love",
            "Your next favourite {$genre} story just landed",
            "Fresh {$genre} picks are waiting in your profile",
        ], $seed);

        $text = "Hi {$name}, {$hook}. Come back this week and enjoy {$offer}.";

        return ['text' => $text, 'subject' => "{$name}, new {$genre} picks for you", 'offer' => $offer, 'channel' => 'email'];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function onboardingChecklist(array $context): array
    {
        $department = (string) ($context['department'] ?? 'General');
        $role = (string) ($context['job_title'] ?? 'Team member');

        $common = [
            'Sign the employment agreement and policy acknowledgements',
            'Complete security awareness training',
            'Meet your manager for a 30-60-90 day plan',
        ];

        $specific = match (Str::lower($department)) {
            'engineering' => ['Request GitHub organisation access', 'Set up the local development environment', 'Pair on a starter ticket with your onboarding buddy'],
            'sales' => ['Shadow three discovery calls', 'Complete CRM training', 'Review the pricing and discount policy'],
            'marketing' => ['Review brand guidelines', 'Get access to the analytics dashboards', 'Draft a first campaign brief'],
            'hr', 'people' => ['Review HRIS administration guide', 'Complete data-privacy training', 'Meet the payroll partner'],
            default => ['Review team documentation', 'Schedule introductions with key stakeholders', 'Complete role-specific training modules'],
        };

        $items = array_merge($common, $specific);

        return [
            'text' => "Onboarding checklist for {$role} ({$department}):\n- ".implode("\n- ", $items),
            'checklist' => $items,
            'department' => $department,
            'role' => $role,
        ];
    }

    private function generic(int $seed, string $prompt): string
    {
        $opening = $this->pick(['Here is a draft:', 'Suggested copy:', 'Generated response:'], $seed);

        return $opening.' '.Str::limit(trim($prompt), 160).' — '.$this->pick(['concise and on-brand.', 'ready for review.', 'tuned for clarity.'], $seed, 2);
    }
}
