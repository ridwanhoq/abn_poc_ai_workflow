<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\Contracts\ContentModerator;

class ModerationNode extends AbstractAiNode
{
    protected string $icon = 'shield-alert';

    public function __construct(private readonly ContentModerator $moderator) {}

    public function type(): string
    {
        return 'ai.moderation';
    }

    public function label(): string
    {
        return 'Content Moderation';
    }

    public function description(): string
    {
        return 'Maturity rating and timestamped content warnings. Mock adapter: keyword rules + sample timestamps.';
    }

    public function integration(): ?string
    {
        return 'mock_moderation';
    }

    public function configSchema(): array
    {
        return [
            $this->field('text', 'Text to moderate', 'textarea', ['required' => true, 'placeholder' => '{{ nodes.transcribe.output.text }}']),
            $this->field('asset_id', 'Media asset ID (optional)', 'text'),
        ];
    }

    public function outputSchema(): array
    {
        return ['maturity_rating' => 'Suggested rating', 'content_warnings' => 'Timestamped warnings', 'requires_human_review' => 'Escalation flag'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::ok($this->moderator->moderate((string) $input->get('text', ''), ['asset_id' => $input->get('asset_id')]));
    }
}
