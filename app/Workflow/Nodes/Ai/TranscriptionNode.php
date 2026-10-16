<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\Contracts\Transcriber;

class TranscriptionNode extends AbstractAiNode
{
    protected string $icon = 'audio-lines';

    public function __construct(private readonly Transcriber $transcriber) {}

    public function type(): string
    {
        return 'media.transcription';
    }

    public function label(): string
    {
        return 'Video Transcription';
    }

    public function description(): string
    {
        return 'Speech-to-text. Mock adapter returns a deterministic sample transcript.';
    }

    public function integration(): ?string
    {
        return 'mock_transcription';
    }

    public function configSchema(): array
    {
        return [
            $this->assetField(),
            $this->field('language', 'Spoken language', 'text', ['default' => 'en']),
        ];
    }

    public function outputSchema(): array
    {
        return ['text' => 'Full transcript', 'segments' => 'Timed segments', 'word_count' => 'Words'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::ok($this->transcriber->transcribe($this->loadAsset($input->get('asset_id')), (string) $input->get('language', 'en')));
    }
}
