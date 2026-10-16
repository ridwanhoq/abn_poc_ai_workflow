<?php

namespace App\Workflow\Nodes\Triggers;

class WebhookTrigger extends AbstractTrigger
{
    protected string $icon = 'webhook';

    public function type(): string
    {
        return 'trigger.webhook';
    }

    public function label(): string
    {
        return 'Webhook Trigger';
    }

    public function description(): string
    {
        return 'Starts the workflow from a signed HTTP POST (HMAC-SHA256, rate limited).';
    }

    protected function triggerFields(): array
    {
        return [
            $this->field('required_fields', 'Required payload fields', 'list', ['default' => [], 'expression' => false, 'help' => 'Requests missing any of these top-level fields are rejected with 422.']),
        ];
    }
}
