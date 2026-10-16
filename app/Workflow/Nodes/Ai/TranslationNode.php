<?php

namespace App\Workflow\Nodes\Ai;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;
use App\Workflow\Integrations\Contracts\Translator;

class TranslationNode extends AbstractAiNode
{
    protected string $icon = 'languages';

    public function __construct(private readonly Translator $translator) {}

    public function type(): string
    {
        return 'ai.translation';
    }

    public function label(): string
    {
        return 'AI Translation';
    }

    public function description(): string
    {
        return 'Translates text. Mock adapter: pseudo-translation tagged with the language code.';
    }

    public function integration(): ?string
    {
        return 'mock_translation';
    }

    public function configSchema(): array
    {
        return [
            $this->field('text', 'Text', 'textarea', ['required' => true]),
            $this->field('target_language', 'Target language', 'text', ['required' => true, 'default' => 'es']),
            $this->field('source_language', 'Source language', 'text'),
        ];
    }

    public function outputSchema(): array
    {
        return ['text' => 'Translated text', 'target_language' => 'Language code'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        return NodeResult::ok($this->translator->translate((string) $input->get('text'), (string) $input->get('target_language'), $input->get('source_language')));
    }
}
