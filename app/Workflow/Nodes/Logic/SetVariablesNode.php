<?php

namespace App\Workflow\Nodes\Logic;

use App\Workflow\Engine\NodeInput;
use App\Workflow\Engine\NodeResult;

class SetVariablesNode extends AbstractLogicNode
{
    protected string $icon = 'variable';

    public function type(): string
    {
        return 'logic.set_variables';
    }

    public function label(): string
    {
        return 'Set Variables';
    }

    public function description(): string
    {
        return 'Stores values available to later nodes as {{ variables.name }}.';
    }

    public function configSchema(): array
    {
        return [
            $this->field('variables', 'Variables', 'keyvalue', ['required' => true, 'default' => ['name' => 'value']]),
        ];
    }

    protected function rules(array $config): array
    {
        foreach (array_keys((array) ($config['variables'] ?? [])) as $name) {
            if (! preg_match('/^[A-Za-z_]\w{0,59}$/', (string) $name)) {
                return ['variables' => "Variable name \"{$name}\" must start with a letter and contain only letters, numbers and underscores."];
            }
        }

        return [];
    }

    public function outputSchema(): array
    {
        return ['*' => 'The variables that were set'];
    }

    public function execute(NodeInput $input): NodeResult
    {
        $variables = (array) $input->get('variables', []);
        $input->context->variables = array_merge($input->context->variables, $variables);

        return NodeResult::ok($variables);
    }
}
