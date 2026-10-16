<?php

namespace App\Workflow\Nodes\Triggers;

class ManualTrigger extends AbstractTrigger
{
    protected string $icon = 'mouse-pointer-click';

    public function type(): string
    {
        return 'trigger.manual';
    }

    public function label(): string
    {
        return 'Manual Trigger';
    }

    public function description(): string
    {
        return 'Runs when an authorized user clicks Run.';
    }
}
