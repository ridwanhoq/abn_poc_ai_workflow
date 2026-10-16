<?php

namespace App\Workflow\Nodes\Triggers;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Nodes\AbstractNode;

/**
 * Trigger nodes start a graph. Their output is the trigger payload. Every
 * trigger has a sample payload used for manual test runs.
 */
abstract class AbstractTrigger extends AbstractNode
{
    public function category(): string
    {
        return 'trigger';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function triggerFields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function samplePayload(): array
    {
        return [];
    }

    public function configSchema(): array
    {
        return [
            ...$this->triggerFields(),
            $this->field('sample_payload', 'Sample payload (test runs)', 'json', [
                'default' => $this->samplePayload(),
                'expression' => false,
                'resolve' => false,
                'help' => 'Used as the trigger data when the workflow is run manually as a test.',
            ]),
        ];
    }

    public function outputSchema(): array
    {
        return ['*' => 'The trigger payload'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::ok($input->context->trigger);
    }
}
