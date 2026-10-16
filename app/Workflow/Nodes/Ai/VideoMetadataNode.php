<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\Contracts\VideoProcessor;

class VideoMetadataNode extends AbstractAiNode
{
    protected string $icon = 'film';

    public function __construct(private readonly VideoProcessor $video) {}

    public function type(): string
    {
        return 'media.video_metadata';
    }

    public function label(): string
    {
        return 'Video Metadata Extraction';
    }

    public function description(): string
    {
        return 'Reads file size/MIME from the local file; codec, duration and resolution are simulated.';
    }

    public function integration(): ?string
    {
        return 'mock_video';
    }

    public function configSchema(): array
    {
        return [$this->assetField()];
    }

    public function outputSchema(): array
    {
        return ['duration_seconds' => 'Duration', 'resolution' => 'WxH', 'video_codec' => 'Codec', 'file_found' => 'Whether the local file exists'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::ok($this->video->metadata($this->loadAsset($input->get('asset_id'))));
    }
}
