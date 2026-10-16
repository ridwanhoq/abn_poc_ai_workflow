<?php

namespace App\Listeners;

use App\Events\MediaAssetUploaded;
use App\Workflow\Triggers\TriggerDispatcher;

class StartFileUploadWorkflows
{
    public function __construct(private readonly TriggerDispatcher $dispatcher) {}

    public function handle(MediaAssetUploaded $event): void
    {
        $this->dispatcher->fileUploaded($event->asset);
    }
}
