<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;

class DelayNode extends AbstractLogicNode
{
    protected string $icon = 'timer';

    public function type(): string
    {
        return 'logic.delay';
    }

    public function label(): string
    {
        return 'Delay / Wait';
    }

    public function description(): string
    {
        return 'Pauses the workflow. Short delays sleep inline; longer delays put the execution in "waiting" and resume via the queue.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('seconds', 'Delay (seconds)', 'number', ['required' => true, 'default' => 5, 'min' => 0, 'max' => 604800, 'expression' => false]),
        ];
    }

    public function outputSchema(): array
    {
        return ['waited_seconds' => 'Delay applied', 'input' => 'Upstream data'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $seconds = (int) $input->get('seconds', 0);
        $output = ['waited_seconds' => $seconds, 'input' => $input->data];

        if ($seconds <= (int) config('workflows.inline_delay_max_seconds', 5)) {
            if ($seconds > 0) {
                sleep($seconds);
            }

            return NodeResult::ok($output + ['mode' => 'inline']);
        }

        return NodeResult::wait($output + ['mode' => 'queued_resume'], $seconds);
    }
}
