<?php

namespace App\Workflow\Nodes\Notifications;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Nodes\AbstractNode;
use App\Workflow\Support\Redactor;
use Illuminate\Support\Facades\Log;

class WriteLogNode extends AbstractNode
{
    protected string $icon = 'notebook-pen';

    public function type(): string
    {
        return 'notify.log';
    }

    public function label(): string
    {
        return 'Write Laravel Log';
    }

    public function category(): string
    {
        return 'notification';
    }

    public function description(): string
    {
        return 'Writes a line to the Laravel application log.';
    }

    public function integration(): ?string
    {
        return 'laravel_log';
    }

    public function configSchema(): array
    {
        return [
            $this->field('level', 'Level', 'select', ['default' => 'info', 'expression' => false, 'options' => $this->options(['debug' => 'Debug', 'info' => 'Info', 'notice' => 'Notice', 'warning' => 'Warning', 'error' => 'Error'])]),
            $this->field('message', 'Message', 'textarea', ['required' => true]),
            $this->field('context', 'Context', 'keyvalue', ['default' => []]),
        ];
    }

    public function outputSchema(): array
    {
        return ['logged' => 'true', 'line' => 'The written line'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        // Secret variable values never reach the application log.
        $redactor = app(Redactor::class);
        $line = (string) $redactor->maskSecrets('[Workflow: '.$input->context->workflow->name.'] '.$input->get('message'), $input->context->secrets);
        $context = (array) $redactor->maskSecrets((array) $input->get('context', []), $input->context->secrets);
        Log::log((string) $input->get('level', 'info'), $line, $context + ['execution_id' => $input->context->execution->id]);

        return NodeResult::ok(['logged' => true, 'line' => $line]);
    }
}
