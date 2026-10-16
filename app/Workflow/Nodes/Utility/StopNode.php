<?php

namespace App\Workflow\Nodes\Utility;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Nodes\AbstractNode;

class StopNode extends AbstractNode
{
    protected string $icon = 'octagon-x';

    public function type(): string
    {
        return 'utility.stop';
    }

    public function label(): string
    {
        return 'Stop Workflow';
    }

    public function category(): string
    {
        return 'utility';
    }

    public function description(): string
    {
        return 'Ends the execution immediately as success or failure.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('status', 'End status', 'select', ['default' => 'success', 'expression' => false, 'options' => $this->options(['success' => 'Success', 'failed' => 'Failed'])]),
            $this->field('message', 'Message', 'text', ['default' => 'Stopped by workflow']),
        ];
    }

    public function outputHandles(array $config): array
    {
        return [];
    }

    public function outputSchema(): array
    {
        return ['message' => 'Stop reason'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::stop(['message' => (string) $input->get('message', 'Stopped by workflow')], (string) $input->get('status', 'success'));
    }
}
