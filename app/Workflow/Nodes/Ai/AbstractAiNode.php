<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Data\DataModelRegistry;
use App\Workflow\Exceptions\NodeExecutionException;
use App\Workflow\Nodes\AbstractNode;

abstract class AbstractAiNode extends AbstractNode
{
    public function category(): string
    {
        return 'ai_media';
    }

    public function implementation(): string
    {
        return self::SIMULATED;
    }

    /**
     * @return array<string, mixed>
     */
    protected function assetField(): array
    {
        return $this->field('asset_id', 'Media asset ID', 'text', ['required' => true, 'placeholder' => '{{ trigger.asset_id }}']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadAsset(mixed $id): array
    {
        return app(DataModelRegistry::class)->find('media_assets', $id)
            ?? throw new NodeExecutionException('Media asset #'.json_encode($id).' was not found.');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function aspectRatios(): array
    {
        return $this->options(['16:9' => '16:9 (landscape)', '9:16' => '9:16 (vertical)', '1:1' => '1:1 (square)', '4:3' => '4:3', '2:3' => '2:3 (poster)']);
    }
}
