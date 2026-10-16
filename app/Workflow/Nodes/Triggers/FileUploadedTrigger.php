<?php

namespace App\Workflow\Nodes\Triggers;

class FileUploadedTrigger extends AbstractTrigger
{
    protected string $icon = 'file-up';

    public function type(): string
    {
        return 'trigger.file_uploaded';
    }

    public function label(): string
    {
        return 'File Uploaded Trigger';
    }

    public function description(): string
    {
        return 'Runs when a media asset is uploaded through the platform (local disk).';
    }

    protected function triggerFields(): array
    {
        return [
            $this->field('mime_prefix', 'MIME type prefix', 'text', ['default' => 'video/', 'expression' => false]),
        ];
    }

    protected function samplePayload(): array
    {
        return ['asset_id' => 1, 'title' => 'Sample asset', 'file_path' => 'media/sample.mp4', 'language' => 'en'];
    }
}
