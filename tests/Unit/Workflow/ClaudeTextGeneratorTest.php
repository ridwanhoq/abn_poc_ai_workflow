<?php

namespace Tests\Unit\Workflow;

use App\Workflow\Exceptions\NodeExecutionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeClaude;
use Tests\TestCase;

class ClaudeTextGeneratorTest extends TestCase
{
    #[Test]
    public function it_requests_structured_output_and_maps_the_shared_field_contract(): void
    {
        $fake = (new FakeClaude)->push(FakeClaude::json([
            'summary' => 'A keeper guards a lighthouse.', 'genres' => ['Drama'], 'keywords' => ['sea'], 'tags' => ['drama'], 'mood' => 'tense',
        ]));

        $result = $fake->generator()->generate('media_metadata', 'Describe the title', ['title' => 'The Last Lighthouse']);

        $this->assertSame('A keeper guards a lighthouse.', $result['summary']);
        $this->assertSame('A keeper guards a lighthouse.', $result['text']);
        $this->assertSame(['Drama'], $result['genres']);
        $this->assertFalse($result['simulated']);
        $this->assertSame('anthropic', $result['provider']);
        $this->assertSame(['prompt_tokens' => 120, 'completion_tokens' => 45], $result['usage']);

        $request = $fake->requests[0];
        $this->assertSame('claude-opus-5-5', $request['body']['model']);
        $this->assertSame('json_schema', $request['body']['output_config']['format']['type']);
        $this->assertSame(['summary', 'genres', 'keywords', 'tags', 'mood'], $request['body']['output_config']['format']['schema']['required']);
        $this->assertFalse($request['body']['output_config']['format']['schema']['additionalProperties']);
        $this->assertSame('low', $request['body']['output_config']['effort']);
        $this->assertSame('default', $request['body']['fallbacks']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', implode(',', $request['headers']['anthropic-beta'] ?? []));
        $this->assertArrayNotHasKey('thinking', $request['body'], 'Opus 5.5 thinking is always on; it must not be sent as disabled');
        $this->assertStringContainsString('<context>', $request['body']['messages'][0]['content']);
    }

    #[Test]
    public function checklists_are_rendered_into_text(): void
    {
        $fake = (new FakeClaude)->push(FakeClaude::json(['checklist' => ['Sign contract', 'Set up laptop'], 'department' => 'Engineering', 'role' => 'Backend Engineer']));

        $result = $fake->generator()->generate('onboarding_checklist', 'Checklist please', ['department' => 'Engineering']);

        $this->assertSame(['Sign contract', 'Set up laptop'], $result['checklist']);
        $this->assertStringContainsString('- Set up laptop', $result['text']);
    }

    #[Test]
    public function refusals_and_api_errors_become_node_errors(): void
    {
        $fake = (new FakeClaude)
            ->push(FakeClaude::json([], 'refusal'))
            ->push(FakeClaude::error(401, 'authentication_error'))
            ->push(FakeClaude::error(429, 'rate_limit_error'));
        $generator = $fake->generator();

        foreach (['declined this request (cyber)', 'rejected the API key', 'rate limit'] as $expected) {
            try {
                $generator->generate('generic', 'Hello');
                $this->fail('Expected NodeExecutionException');
            } catch (NodeExecutionException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }
}
