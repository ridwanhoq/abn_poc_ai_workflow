<?php

namespace App\Workflow\Nodes\Triggers;

use Cron\CronExpression;

class UserVerificationPendingTrigger extends AbstractTrigger
{
    protected string $icon = 'user-check';

    public function type(): string
    {
        return 'trigger.user_verification_pending';
    }

    public function label(): string
    {
        return 'User Verification Pending Trigger';
    }

    public function description(): string
    {
        return 'On a schedule, starts one execution per user whose verified_at is NULL.';
    }

    protected function triggerFields(): array
    {
        return [
            $this->field('cron', 'Check schedule (cron)', 'text', ['required' => true, 'default' => '0 10 * * *', 'expression' => false]),
            $this->field('min_account_age_hours', 'Minimum account age (hours)', 'number', ['default' => 24, 'min' => 0, 'max' => 8760, 'expression' => false]),
            $this->field('max_users_per_run', 'Max users per run', 'number', ['default' => 100, 'min' => 1, 'max' => 5000, 'expression' => false]),
        ];
    }

    protected function samplePayload(): array
    {
        return ['user_id' => 1, 'name' => 'Sample User', 'email' => 'sample.user@example.test', 'verified_at' => null];
    }

    protected function rules(array $config): array
    {
        return ! empty($config['cron']) && ! CronExpression::isValidExpression((string) $config['cron'])
            ? ['cron' => 'Cron expression is invalid.']
            : [];
    }
}
