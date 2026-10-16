<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Integrations\Contracts\VideoProcessor;

class ThumbnailNode extends AbstractAiNode
{
    protected string $icon = 'image-play';

    public function __construct(private readonly VideoProcessor $video) {}

    public function type(): string
    {
        return 'media.thumbnail';
    }

    public function label(): string
    {
        return 'Thumbnail Generation';
    }

    public function description(): string
    {
        return 'Selects candidate frames and a thumbnail spec. Mock adapter: no image is rendered.';
    }

    public function integration(): ?string
    {
        return 'mock_video';
    }

    public function configSchema(): array
    {
        return [
            $this->field('content', 'Content record', 'text', ['required' => true, 'placeholder' => '{{ nodes.load_content.output.record }}']),
            $this->field('prompt', 'Prompt', 'textarea'),
            $this->field('aspect_ratio', 'Aspect ratio', 'select', ['default' => '16:9', 'options' => $this->aspectRatios()]),
            $this->field('resolution', 'Output resolution', 'text', ['default' => '1920x1080']),
            // Future options (brand templates, font selection, image placement) are accepted and passed through.
            $this->field('brand_template', 'Brand template (future)', 'text', ['help' => 'Reserved for the production renderer.']),
        ];
    }

    public function outputSchema(): array
    {
        return ['candidate_frames' => 'Suggested frames', 'file_generated' => 'false for the mock adapter'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $content = $input->get('content');
        if (! is_array($content)) {
            throw new NodeExecutionException('Content must resolve to a record object.');
        }

        return NodeResult::ok($this->video->thumbnail($content, [
            'prompt' => $input->get('prompt'),
            'aspect_ratio' => $input->get('aspect_ratio', '16:9'),
            'resolution' => $input->get('resolution', '1920x1080'),
            'brand_template' => $input->get('brand_template'),
        ]));
    }
}
