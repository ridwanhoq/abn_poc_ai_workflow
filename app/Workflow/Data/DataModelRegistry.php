<?php

namespace App\Workflow\Data;

use App\Workflow\Exceptions\NodeExecutionException;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gatekeeper for data-operation nodes. Only models and columns whitelisted in
 * config('workflows.data_models') are reachable; all filters go through the
 * query builder with bound parameters (no raw SQL from workflow definitions).
 */
class DataModelRegistry
{
    public const OPERATORS = [
        '=' => 'equals',
        '!=' => 'not equals',
        '>' => 'greater than',
        '>=' => 'greater or equal',
        '<' => 'less than',
        '<=' => 'less or equal',
        'is_null' => 'is null',
        'is_not_null' => 'is not null',
        'in' => 'in list',
        'not_in' => 'not in list',
        'like' => 'contains text',
        'older_than_days' => 'older than N days',
        'newer_than_days' => 'within last N days',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return config('workflows.data_models', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $alias): array
    {
        return $this->definitions()[$alias] ?? throw new NodeExecutionException("Data model \"{$alias}\" is not available to workflows.");
    }

    public function exists(string $alias): bool
    {
        return isset($this->definitions()[$alias]);
    }

    /**
     * @return Builder<Model>
     */
    public function query(string $alias): Builder
    {
        /** @var class-string<Model> $class */
        $class = $this->definition($alias)['model'];

        return $class::query();
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @return Builder<Model>
     */
    public function filtered(string $alias, array $filters): Builder
    {
        $query = $this->query($alias);
        $readable = $this->definition($alias)['readable'];

        foreach ($filters as $filter) {
            $column = (string) ($filter['column'] ?? '');
            $operator = (string) ($filter['operator'] ?? '=');
            $value = $filter['value'] ?? null;

            if (! in_array($column, $readable, true)) {
                throw new NodeExecutionException("Column \"{$column}\" cannot be filtered on {$alias}.");
            }

            match ($operator) {
                '=', '!=', '>', '>=', '<', '<=' => $query->where($column, $operator, $this->scalar($value)),
                'is_null' => $query->whereNull($column),
                'is_not_null' => $query->whereNotNull($column),
                'in' => $query->whereIn($column, $this->listOf($value)),
                'not_in' => $query->whereNotIn($column, $this->listOf($value)),
                'like' => $query->where($column, 'like', '%'.addcslashes((string) $this->scalar($value), '%_\\').'%'),
                'older_than_days' => $query->where($column, '<', CarbonImmutable::now()->subDays((int) $value)),
                'newer_than_days' => $query->where($column, '>=', CarbonImmutable::now()->subDays((int) $value)),
                default => throw new NodeExecutionException("Unsupported filter operator \"{$operator}\"."),
            };
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(string $alias, Model $model): array
    {
        $out = [];
        foreach ($this->definition($alias)['readable'] as $column) {
            $value = $model->getAttribute($column);
            $out[$column] = $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->toIso8601String() : $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $alias, mixed $id): ?array
    {
        $model = $this->query($alias)->whereKey($this->scalar($id))->first();

        return $model ? $this->serialize($alias, $model) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(string $alias, array $attributes): Model
    {
        $allowed = $this->definition($alias)['writable'];
        $this->assertColumns($alias, $attributes, $allowed, 'written');

        /** @var class-string<Model> $class */
        $class = $this->definition($alias)['model'];

        return $class::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(string $alias, mixed $id, array $attributes): Model
    {
        $allowed = $this->definition($alias)['updatable'];
        $this->assertColumns($alias, $attributes, $allowed, 'updated');

        $model = $this->query($alias)->whereKey($this->scalar($id))->first()
            ?? throw new NodeExecutionException("{$alias} record #".json_encode($id).' not found.');
        $model->fill($attributes)->save();

        return $model;
    }

    /**
     * Stream records in chunks (chunkById) so large tables are never loaded at once.
     *
     * @param  array<string, mixed>  $descriptor  {model, filters, batch_size} as produced by Find Database Records
     * @param  CursorStats  $stats  receives the number of batches fetched
     * @return Generator<int, array<string, mixed>>
     */
    public function cursor(array $descriptor, CursorStats $stats): Generator
    {
        $alias = is_string($descriptor['model'] ?? null) ? $descriptor['model'] : '';
        $batch = max(1, min(1000, (int) ($descriptor['batch_size'] ?? 100)));
        $lastId = 0;

        // Manual keyset pagination equivalent to chunkById(), written as a generator
        // so the engine can consume one record at a time between batches.
        while (true) {
            $rows = $this->filtered($alias, array_values((array) ($descriptor['filters'] ?? [])))
                ->where($this->query($alias)->getModel()->getQualifiedKeyName(), '>', $lastId)
                ->orderBy($this->query($alias)->getModel()->getQualifiedKeyName())
                ->limit($batch)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $stats->batches++;

            foreach ($rows as $row) {
                $lastId = $row->getKey();
                yield $this->serialize($alias, $row);
            }

            if ($rows->count() < $batch) {
                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     */
    private function assertColumns(string $alias, array $attributes, array $allowed, string $verb): void
    {
        $illegal = array_diff(array_keys($attributes), $allowed);

        if ($allowed === []) {
            throw new NodeExecutionException("Records of \"{$alias}\" cannot be {$verb} by workflows.");
        }

        if ($illegal !== []) {
            throw new NodeExecutionException('Column(s) '.implode(', ', $illegal)." of \"{$alias}\" cannot be {$verb} by workflows.");
        }
    }

    private function scalar(mixed $value): mixed
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : array_map('trim', explode(',', (string) $value));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function selectOptions(): array
    {
        $options = [];
        foreach ($this->definitions() as $alias => $definition) {
            $options[] = ['value' => $alias, 'label' => $definition['label']];
        }

        return $options;
    }
}
