<?php

namespace App\Workflow\Nodes\Utility;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Nodes\AbstractNode;

class ReturnOutputNode extends AbstractNode
{
    protected string $icon = 'flag';

    public function type(): string
    {
        return 'utility.return';
    }

    public function label(): string
    {
        return 'Return Output';
    }

    public function category(): string
    {
        return 'utility';
    }

    public function description(): string
    {
        return 'Sets the execution result (shown in history and returned to parent workflows).';
    }

    public function configSchema(): array
    {
        return [
            $this->field('output', 'Output', 'json', ['required' => true, 'default' => ['status' => 'done']]),
        ];
    }

    public function outputSchema(): array
    {
        return ['*' => 'The resolved output object'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $output = $input->get('output', []);
        $output = is_array($output) ? $output : ['value' => $output];
        $input->context->returnOutput = $output;

        return NodeResult::ok($output);
    }
}
