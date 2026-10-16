<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Integrations\Contracts\VideoProcessor;

class TrailerNode extends AbstractAiNode
{
    protected string $icon = 'clapperboard';

    public function __construct(private readonly VideoProcessor $video) {}

    public function type(): string
    {
        return 'media.trailer';
    }

    public function label(): string
    {
        return 'Trailer Generation';
    }

    public function description(): string
    {
        return 'Builds a trailer storyboard (scenes, overlays, music cues). Mock adapter: no video is rendered.';
    }

    public function integration(): ?string
    {
        return 'mock_video';
    }

    public function configSchema(): array
    {
        return [
            $this->field('content', 'Content record', 'text', ['required' => true, 'placeholder' => '{{ nodes.load_content.output.record }}']),
            $this->field('target_duration', 'Target duration (seconds)', 'number', ['default' => 60, 'min' => 15, 'max' => 180]),
            $this->field('language', 'Language', 'text', ['default' => 'en']),
            $this->field('aspect_ratio', 'Aspect ratio', 'select', ['default' => '16:9', 'options' => $this->aspectRatios()]),
        ];
    }

    public function outputSchema(): array
    {
        return ['scenes' => 'Storyboard scenes', 'target_duration_seconds' => 'Duration', 'video_rendered' => 'false for the mock adapter'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $content = $input->get('content');
        if (! is_array($content)) {
            throw new NodeExecutionException('Content must resolve to a record object.');
        }

        return NodeResult::ok($this->video->storyboard($content, [
            'target_duration' => (int) $input->get('target_duration', 60),
            'language' => $input->get('language', 'en'),
            'aspect_ratio' => $input->get('aspect_ratio', '16:9'),
        ]));
    }
}
