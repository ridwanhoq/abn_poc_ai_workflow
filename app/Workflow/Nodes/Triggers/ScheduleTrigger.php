<?php

namespace App\Workflow\Nodes\Triggers;

use Cron\CronExpression;

class ScheduleTrigger extends AbstractTrigger
{
    protected string $icon = 'calendar-clock';

    public function type(): string
    {
        return 'trigger.schedule';
    }

    public function label(): string
    {
        return 'Schedule Trigger';
    }

    public function description(): string
    {
        return 'Runs on a cron schedule via the Laravel Scheduler (active workflows only).';
    }

    protected function triggerFields(): array
    {
        return [
            $this->field('cron', 'Cron expression', 'text', ['required' => true, 'default' => '0 9 * * *', 'expression' => false, 'placeholder' => '0 9 * * *', 'help' => 'Standard 5-field cron, e.g. "0 9 * * 1-5" = weekdays at 09:00.']),
            $this->field('timezone', 'Timezone', 'text', ['default' => 'UTC', 'expression' => false]),
        ];
    }

    protected function rules(array $config): array
    {
        $errors = [];
        if (! empty($config['cron']) && ! CronExpression::isValidExpression((string) $config['cron'])) {
            $errors['cron'] = 'Cron expression is invalid.';
        }
        if (! empty($config['timezone']) && ! in_array($config['timezone'], timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Timezone is not a valid identifier.';
        }

        return $errors;
    }
}
