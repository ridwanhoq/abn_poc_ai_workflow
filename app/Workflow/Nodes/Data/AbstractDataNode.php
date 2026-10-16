<?php

namespace App\Workflow\Nodes\Data;

use App\Workflow\Data\DataModelRegistry;
use App\Workflow\Nodes\AbstractNode;

abstract class AbstractDataNode extends AbstractNode
{
    public function __construct(protected readonly DataModelRegistry $data) {}

    public function category(): string
    {
        return 'data';
    }

    public function integration(): ?string
    {
        return 'database';
    }

    /**
     * @return array<string, mixed>
     */
    protected function modelField(): array
    {
        return $this->field('model', 'Data model', 'select', [
            'required' => true,
            'expression' => false,
            'options' => $this->data->selectOptions(),
            'default' => array_key_first($this->data->definitions()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function modelRules(array $config): array
    {
        $model = (string) ($config['model'] ?? '');

        return $model !== '' && ! $this->data->exists($model) ? ['model' => "Unknown data model \"{$model}\"."] : [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    protected function columnRules(array $config, string $field, string $kind): array
    {
        $model = (string) ($config['model'] ?? '');
        if (! $this->data->exists($model)) {
            return [];
        }

        $allowed = $this->data->definition($model)[$kind];
        $columns = array_keys((array) ($config[$field] ?? []));
        $illegal = array_diff($columns, $allowed);

        if ($allowed === [] && $columns !== []) {
            return [$field => "{$model} records cannot be modified by workflows."];
        }

        return $illegal ? [$field => 'Not allowed for '.$model.': '.implode(', ', $illegal).'. Allowed: '.implode(', ', $allowed).'.'] : [];
    }
}
