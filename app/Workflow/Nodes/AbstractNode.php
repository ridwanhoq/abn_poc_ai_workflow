<?php

namespace App\Workflow\Nodes;

use App\Workflow\Contracts\NodeType;
use App\Workflow\Engine\ConditionEvaluator;
use App\Workflow\Engine\ExpressionResolver;

/**
 * Shared behaviour: schema helpers and schema-driven config validation.
 *
 * Field types: text, textarea, number, select, boolean, json, keyvalue,
 * conditions, filters, list, cases. Every field accepts {{ expressions }}
 * unless it declares 'expression' => false.
 */
abstract class AbstractNode implements NodeType
{
    protected string $icon = 'box';

    public function icon(): string
    {
        return $this->icon;
    }

    public function implementation(): string
    {
        return self::IMPLEMENTED;
    }

    public function integration(): ?string
    {
        return null;
    }

    public function acceptsInput(): bool
    {
        return $this->category() !== 'trigger';
    }

    public function outputHandles(array $config): array
    {
        return ['main'];
    }

    public function outputSchema(): array
    {
        return [];
    }

    /**
     * Extra validation beyond the schema.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function rules(array $config): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function field(string $key, string $label, string $type = 'text', array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => false,
            'expression' => true,
            'resolve' => true,
        ], $extra);
    }

    /**
     * @param  array<string, string>|list<string>  $options
     * @return list<array{value: string, label: string}>
     */
    protected function options(array $options): array
    {
        $out = [];
        foreach ($options as $value => $label) {
            $out[] = is_int($value)
                ? ['value' => (string) $label, 'label' => (string) $label]
                : ['value' => (string) $value, 'label' => (string) $label];
        }

        return $out;
    }

    /**
     * Default values for the config fields.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->configSchema() as $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$field['key']] = $field['default'];
            }
        }

        return $defaults;
    }

    public function validateConfig(array $config): array
    {
        $resolver = app(ExpressionResolver::class);
        $errors = [];

        foreach ($this->configSchema() as $field) {
            $key = $field['key'];
            $label = $field['label'];
            $value = $config[$key] ?? null;
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($field['required']) {
                    $errors[$key] = "{$label} is required.";
                }

                continue;
            }

            if ($syntax = $resolver->syntaxError($value)) {
                $errors[$key] = "{$label}: {$syntax}";

                continue;
            }

            if (is_string($value) && str_contains($value, '{{')) {
                if (! $field['expression']) {
                    $errors[$key] = "{$label} does not accept expressions.";
                }

                continue;
            }

            $error = match ($field['type']) {
                'number' => $this->checkNumber($value, $field),
                'select' => in_array((string) $value, array_column($field['options'] ?? [], 'value'), true) ? null : "{$label} has an invalid option.",
                'boolean' => is_bool($value) ? null : "{$label} must be true or false.",
                'json', 'keyvalue' => is_array($value) ? null : "{$label} must be a JSON object or list.",
                'list' => is_array($value) || is_string($value) ? null : "{$label} must be a list.",
                'conditions' => $this->checkConditions($value, $label),
                'filters' => $this->checkFilters($value, $label),
                'cases' => is_array($value) && array_is_list($value) ? null : "{$label} must be a list of cases.",
                default => is_scalar($value) ? null : "{$label} must be text.",
            };

            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        return $errors + $this->rules($config);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function checkNumber(mixed $value, array $field): ?string
    {
        if (! is_numeric($value)) {
            return "{$field['label']} must be a number.";
        }
        if (isset($field['min']) && $value < $field['min']) {
            return "{$field['label']} must be at least {$field['min']}.";
        }
        if (isset($field['max']) && $value > $field['max']) {
            return "{$field['label']} may not be greater than {$field['max']}.";
        }

        return null;
    }

    private function checkConditions(mixed $value, string $label): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return "{$label} must be a list of conditions.";
        }

        foreach ($value as $i => $condition) {
            $operator = $condition['operator'] ?? null;
            if (! is_array($condition) || ! array_key_exists((string) $operator, ConditionEvaluator::OPERATORS)) {
                return "{$label}: condition #".($i + 1).' has an invalid operator.';
            }
            if (! in_array($operator, ConditionEvaluator::UNARY, true) && ! array_key_exists('right', $condition)) {
                return "{$label}: condition #".($i + 1).' needs a comparison value.';
            }
        }

        return null;
    }

    private function checkFilters(mixed $value, string $label): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return "{$label} must be a list of filters.";
        }

        foreach ($value as $i => $filter) {
            if (! is_array($filter) || empty($filter['column']) || empty($filter['operator'])) {
                return "{$label}: filter #".($i + 1).' needs a column and an operator.';
            }
        }

        return null;
    }

    public function toArray(): array
    {
        $defaults = $this->defaults();

        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'category' => $this->category(),
            'description' => $this->description(),
            'icon' => $this->icon(),
            'implementation' => $this->implementation(),
            'integration' => $this->integration(),
            'accepts_input' => $this->acceptsInput(),
            'output_handles' => $this->outputHandles($defaults),
            'dynamic_handles' => false,
            'config_schema' => $this->configSchema(),
            'defaults' => $defaults,
            'output_schema' => $this->outputSchema(),
        ];
    }
}
