<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Nodes\AbstractNode;

class InsertExecutionLogNode extends AbstractNode
{
    protected string $icon = 'scroll-text';

    public function type(): string
    {
        return 'data.execution_log';
    }

    public function label(): string
    {
        return 'Insert Execution Log';
    }

    public function category(): string
    {
        return 'data';
    }

    public function description(): string
    {
        return 'Writes an entry to this execution\'s log (shown in execution details).';
    }

    public function configSchema(): array
    {
        return [
            $this->field('level', 'Level', 'select', ['default' => 'info', 'expression' => false, 'options' => $this->options(['info' => 'Info', 'warning' => 'Warning', 'error' => 'Error'])]),
            $this->field('message', 'Message', 'textarea', ['required' => true]),
            $this->field('context', 'Context', 'keyvalue', ['default' => []]),
        ];
    }

    public function outputSchema(): array
    {
        return ['logged' => 'true', 'level' => 'Log level', 'message' => 'The resolved message'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $level = (string) $input->get('level', 'info');
        $message = (string) $input->get('message');
        $input->context->log($level, $message, (array) $input->get('context', []), $input->key());

        return NodeResult::ok(['logged' => true, 'level' => $level, 'message' => $message]);
    }
}
