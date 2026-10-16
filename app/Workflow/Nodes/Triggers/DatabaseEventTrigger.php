<?php

namespace App\Workflow\Nodes\Triggers;

class DatabaseEventTrigger extends AbstractTrigger
{
    protected string $icon = 'database-zap';

    public function type(): string
    {
        return 'trigger.database_event';
    }

    public function label(): string
    {
        return 'Database Event Trigger';
    }

    public function description(): string
    {
        return 'Runs when a record of a supported model is created, updated or deleted.';
    }

    protected function triggerFields(): array
    {
        $models = array_map('strval', array_keys((array) config('workflows.event_models', [])));

        return [
            $this->field('model', 'Model', 'select', ['required' => true, 'default' => $models[0] ?? null, 'options' => $this->options($models), 'expression' => false]),
            $this->field('event', 'Event', 'select', ['required' => true, 'default' => 'created', 'options' => $this->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']), 'expression' => false]),
            $this->field('match', 'Only when fields equal', 'keyvalue', ['default' => [], 'expression' => false, 'help' => 'Optional, e.g. status = published.']),
        ];
    }

    protected function samplePayload(): array
    {
        return ['model' => 'employees', 'event' => 'created', 'record' => ['id' => 1]];
    }
}
