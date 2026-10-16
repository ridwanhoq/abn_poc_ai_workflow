<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\IntegrationManager;
use App\Workflow\Integrations\Mock\MockTextGenerator;
use App\Workflow\Support\AiUsageRecorder;

class AiTextNode extends AbstractAiNode
{
    protected string $icon = 'sparkles';

    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly AiUsageRecorder $usage,
    ) {}

    public function type(): string
    {
        return 'ai.text';
    }

    public function label(): string
    {
        return 'AI Text Generation';
    }

    public function description(): string
    {
        return 'Generates text or structured copy. Uses Claude when the Anthropic integration is enabled; otherwise a deterministic mock (no LLM call).';
    }

    public function implementation(): string
    {
        return $this->integrations->usesRealTextGeneration() ? self::IMPLEMENTED : self::SIMULATED;
    }

    public function integration(): ?string
    {
        return $this->integrations->usesRealTextGeneration() ? 'anthropic' : 'mock_ai_text';
    }

    public function configSchema(): array
    {
        return [
            $this->field('task', 'Task', 'select', ['default' => 'generic', 'expression' => false, 'options' => $this->options(MockTextGenerator::TASKS)]),
            $this->field('prompt', 'Prompt', 'textarea', ['required' => true]),
            $this->field('context', 'Context data', 'json', ['default' => [], 'help' => 'Structured input for the task, e.g. {"title": "{{ trigger.title }}"}.']),
        ];
    }

    public function outputSchema(): array
    {
        return ['text' => 'Generated text', 'simulated' => 'true for the mock adapter', '*' => 'Task-specific structured fields'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $context = $input->get('context', []);

        $output = $this->integrations->textGenerator()->generate(
            (string) $input->get('task', 'generic'),
            (string) $input->get('prompt'),
            is_array($context) ? $context : ['value' => $context],
        );

        $this->usage->record($input->context, $input->key(), $output);

        return NodeResult::ok($output);
    }
}
