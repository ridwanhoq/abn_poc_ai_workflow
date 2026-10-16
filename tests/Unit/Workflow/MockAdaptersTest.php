<?php

namespace Tests\Unit\Workflow;

use App\Workflow\Integrations\Contracts\ContentModerator;
use App\Workflow\Integrations\Contracts\ImageGenerator;
use App\Workflow\Integrations\Contracts\TextGenerator;
use App\Workflow\Integrations\Contracts\Transcriber;
use App\Workflow\Integrations\Contracts\Translator;
use App\Workflow\Integrations\Contracts\VideoProcessor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MockAdaptersTest extends TestCase
{
    #[Test]
    public function mock_text_generation_is_deterministic_structured_and_labelled(): void
    {
        $generator = app(TextGenerator::class);
        $a = $generator->generate('media_metadata', 'prompt', ['title' => 'Test Movie']);
        $b = $generator->generate('media_metadata', 'prompt', ['title' => 'Test Movie']);

        $this->assertSame($a, $b);
        $this->assertTrue($a['simulated']);
        $this->assertSame('mock', $a['provider']);
        $this->assertCount(2, $a['genres']);
        $this->assertNotEmpty($a['keywords']);
        $this->assertStringContainsString('Test Movie', $a['summary']);
    }

    #[Test]
    public function every_mock_adapter_identifies_simulated_output(): void
    {
        $asset = ['id' => 1, 'file_path' => 'missing.mp4', 'language' => 'en', 'duration_seconds' => 1200];

        $outputs = [
            app(ImageGenerator::class)->generate('poster'),
            app(VideoProcessor::class)->metadata($asset),
            app(VideoProcessor::class)->analyzeFrames($asset, 3),
            app(VideoProcessor::class)->storyboard(['id' => 1, 'title' => 'T'], ['target_duration' => 30]),
            app(VideoProcessor::class)->subtitles($asset, 'en', ['es']),
            app(Transcriber::class)->transcribe($asset, 'en'),
            app(ContentModerator::class)->moderate('a fight with a gun'),
            app(Translator::class)->translate('hello', 'fr'),
        ];

        foreach ($outputs as $output) {
            $this->assertTrue($output['simulated']);
            $this->assertStringContainsString('Simulated', $output['notice']);
        }

        $this->assertNull($outputs[0]['image_url'], 'Mock image adapter must not fabricate a downloadable asset');
        $this->assertSame('TV-MA', $outputs[6]['maturity_rating']);
        $this->assertSame('[FR] hello', $outputs[7]['text']);
    }
}
