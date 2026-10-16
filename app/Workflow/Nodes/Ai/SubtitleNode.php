<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\Contracts\VideoProcessor;

class SubtitleNode extends AbstractAiNode
{
    protected string $icon = 'captions';

    public function __construct(private readonly VideoProcessor $video) {}

    public function type(): string
    {
        return 'media.subtitles';
    }

    public function label(): string
    {
        return 'Subtitle Generation';
    }

    public function description(): string
    {
        return 'Plans caption/subtitle tracks per language. Mock adapter: no subtitle files are written.';
    }

    public function integration(): ?string
    {
        return 'mock_video';
    }

    public function configSchema(): array
    {
        return [
            $this->assetField(),
            $this->field('source_language', 'Source language', 'text', ['default' => 'en']),
            $this->field('target_languages', 'Target languages', 'list', ['default' => ['es', 'fr'], 'help' => 'Comma-separated ISO codes or an expression resolving to a list.']),
        ];
    }

    public function outputSchema(): array
    {
        return ['tracks' => 'One entry per language: {language, kind, format, cue_count, status}'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $targets = $input->get('target_languages', []);
        $targets = is_array($targets) ? $targets : array_filter(array_map('trim', explode(',', (string) $targets)));

        return NodeResult::ok($this->video->subtitles(
            $this->loadAsset($input->get('asset_id')),
            (string) $input->get('source_language', 'en'),
            array_values($targets),
        ));
    }
}
