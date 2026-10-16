<?php

namespace App\Workflow\Nodes\Triggers;

class UserCreatedTrigger extends AbstractTrigger
{
    protected string $icon = 'user-plus';

    public function type(): string
    {
        return 'trigger.user_created';
    }

    public function label(): string
    {
        return 'User Created Trigger';
    }

    public function description(): string
    {
        return 'Runs whenever a new user account is created.';
    }

    protected function samplePayload(): array
    {
        return ['user_id' => 1, 'name' => 'Sample User', 'email' => 'sample.user@example.test'];
    }
}
