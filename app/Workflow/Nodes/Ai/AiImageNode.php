<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\Contracts\ImageGenerator;

class AiImageNode extends AbstractAiNode
{
    protected string $icon = 'image';

    public function __construct(private readonly ImageGenerator $generator) {}

    public function type(): string
    {
        return 'ai.image';
    }

    public function label(): string
    {
        return 'AI Image Generation';
    }

    public function description(): string
    {
        return 'Produces an image specification. Mock adapter: no image file is created.';
    }

    public function integration(): ?string
    {
        return 'mock_ai_image';
    }

    public function configSchema(): array
    {
        return [
            $this->field('prompt', 'Prompt', 'textarea', ['required' => true]),
            $this->field('aspect_ratio', 'Aspect ratio', 'select', ['default' => '16:9', 'options' => $this->aspectRatios()]),
            $this->field('resolution', 'Resolution', 'select', ['default' => '1920x1080', 'options' => $this->options(['1280x720', '1920x1080', '3840x2160', '1080x1920', '1080x1080'])]),
            $this->field('style', 'Style', 'text', ['default' => 'cinematic']),
        ];
    }

    public function outputSchema(): array
    {
        return ['image_url' => 'null for the mock adapter', 'file_generated' => 'false for the mock adapter', 'dominant_colors' => 'Suggested palette'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::ok($this->generator->generate((string) $input->get('prompt'), [
            'aspect_ratio' => $input->get('aspect_ratio', '16:9'),
            'resolution' => $input->get('resolution', '1920x1080'),
            'style' => $input->get('style', 'cinematic'),
        ]));
    }
}
