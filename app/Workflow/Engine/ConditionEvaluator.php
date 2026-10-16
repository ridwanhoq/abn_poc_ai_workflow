<?php

namespace App\Workflow\Engine;

use InvalidArgumentException;

/**
 * Evaluates structured conditions used by IF / Filter / Switch nodes.
 * A condition is ['left' => mixed, 'operator' => string, 'right' => mixed]
 * where left/right have already been resolved by the ExpressionResolver.
 */
class ConditionEvaluator
{
    public const OPERATORS = [
        'equals' => 'is equal to',
        'not_equals' => 'is not equal to',
        'gt' => 'is greater than',
        'gte' => 'is greater than or equal to',
        'lt' => 'is less than',
        'lte' => 'is less than or equal to',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'starts_with' => 'starts with',
        'ends_with' => 'ends with',
        'in' => 'is one of',
        'not_in' => 'is not one of',
        'is_empty' => 'is empty',
        'is_not_empty' => 'is not empty',
        'is_null' => 'is null',
        'is_not_null' => 'is not null',
        'is_true' => 'is true',
        'is_false' => 'is false',
        'is_email' => 'is a valid email',
        'is_not_email' => 'is not a valid email',
    ];

    public const UNARY = ['is_empty', 'is_not_empty', 'is_null', 'is_not_null', 'is_true', 'is_false', 'is_email', 'is_not_email'];

    /**
     * Coerce a resolved config value into a list of condition arrays.
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize(mixed $conditions): array
    {
        if (! is_array($conditions)) {
            return [];
        }

        return array_values(array_filter($conditions, 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    public function evaluateAll(array $conditions, string $combinator = 'all'): bool
    {
        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            $result = $this->evaluate($condition['left'] ?? null, (string) ($condition['operator'] ?? 'equals'), $condition['right'] ?? null);

            if ($combinator === 'any' && $result) {
                return true;
            }
            if ($combinator !== 'any' && ! $result) {
                return false;
            }
        }

        return $combinator !== 'any';
    }

    public function evaluate(mixed $left, string $operator, mixed $right = null): bool
    {
        return match ($operator) {
            'equals' => $this->loosely($left) == $this->loosely($right),
            'not_equals' => $this->loosely($left) != $this->loosely($right),
            'gt' => is_numeric($left) && is_numeric($right) ? $left > $right : (string) $left > (string) $right,
            'gte' => is_numeric($left) && is_numeric($right) ? $left >= $right : (string) $left >= (string) $right,
            'lt' => is_numeric($left) && is_numeric($right) ? $left < $right : (string) $left < (string) $right,
            'lte' => is_numeric($left) && is_numeric($right) ? $left <= $right : (string) $left <= (string) $right,
            'contains' => is_array($left) ? in_array($right, $left) : str_contains((string) $this->scalar($left), (string) $this->scalar($right)),
            'not_contains' => ! $this->evaluate($left, 'contains', $right),
            'starts_with' => str_starts_with((string) $this->scalar($left), (string) $this->scalar($right)),
            'ends_with' => str_ends_with((string) $this->scalar($left), (string) $this->scalar($right)),
            'in' => in_array($this->loosely($left), array_map(fn ($v) => $this->loosely($v), $this->listOf($right))),
            'not_in' => ! $this->evaluate($left, 'in', $right),
            'is_empty' => $left === null || $left === '' || $left === [],
            'is_not_empty' => ! ($left === null || $left === '' || $left === []),
            'is_null' => $left === null,
            'is_not_null' => $left !== null,
            'is_true' => $left === true || in_array($left, [1, '1', 'true', 'yes'], true),
            'is_false' => ! $this->evaluate($left, 'is_true'),
            'is_email' => is_string($left) && filter_var($left, FILTER_VALIDATE_EMAIL) !== false,
            'is_not_email' => ! $this->evaluate($left, 'is_email'),
            default => throw new InvalidArgumentException("Unsupported operator: {$operator}"),
        };
    }

    private function loosely(mixed $value): mixed
    {
        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }

        return is_array($value) ? json_encode($value) : $value;
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
        if (is_array($value)) {
            return array_values($value);
        }

        return array_map('trim', explode(',', (string) $value));
    }
}
