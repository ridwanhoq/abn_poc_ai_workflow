<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\Contracts\VideoProcessor;

class FrameAnalysisNode extends AbstractAiNode
{
    protected string $icon = 'scan-eye';

    public function __construct(private readonly VideoProcessor $video) {}

    public function type(): string
    {
        return 'media.frame_analysis';
    }

    public function label(): string
    {
        return 'Video Frame Analysis';
    }

    public function description(): string
    {
        return 'Vision labels for representative frames. Mock adapter: sample labels.';
    }

    public function integration(): ?string
    {
        return 'mock_video';
    }

    public function configSchema(): array
    {
        return [
            $this->assetField(),
            $this->field('frame_count', 'Frames to analyze', 'number', ['default' => 5, 'min' => 1, 'max' => 20, 'expression' => false]),
        ];
    }

    public function outputSchema(): array
    {
        return ['frames' => 'Per-frame labels', 'objects' => 'Distinct objects detected'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::ok($this->video->analyzeFrames($this->loadAsset($input->get('asset_id')), (int) $input->get('frame_count', 5)));
    }
}
