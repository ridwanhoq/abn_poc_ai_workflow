<?php

namespace App\Workflow\Engine;

use App\Workflow\Exceptions\ExpressionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Safe template resolver for `{{ … }}` expressions.
 *
 * Supported syntax (no eval, no method calls, no arbitrary PHP):
 *
 *   {{ trigger.user_id }}                       dotted path into the scope
 *   {{ nodes.find_user.output.email }}          node outputs
 *   {{ nodes.find_users.output.records[0].id }} numeric index (also `.0.`)
 *   {{ variables.campaign_name }}               workflow / global variables
 *   {{ item.email }} / {{ index }}              current loop item and index
 *   {{ execution.id }}, {{ workflow.name }}, {{ now }}
 *   {{ 'literal' }}, {{ 42 }}, {{ true }}
 *   {{ trigger.name | upper | default('n/a') }} filter pipeline
 *
 * When a string consists of exactly one expression the raw typed value is
 * returned (arrays, numbers, booleans); otherwise every expression is
 * stringified and interpolated.
 */
class ExpressionResolver
{
    public const ROOTS = ['trigger', 'nodes', 'variables', 'item', 'index', 'execution', 'workflow', 'now'];

    public const FILTERS = [
        'upper', 'lower', 'title', 'trim', 'slug', 'default', 'json', 'length', 'count', 'first', 'last',
        'join', 'round', 'int', 'float', 'string', 'bool', 'date', 'add_days', 'sub_days', 'keys', 'pluck',
        'sum', 'slice', 'replace', 'truncate', 'split', 'not',
    ];

    private const SINGLE = '/^\s*\{\{\s*(.+?)\s*\}\}\s*$/s';

    private const ANY = '/\{\{\s*(.+?)\s*\}\}/s';

    /**
     * Recursively resolve expressions inside strings / arrays.
     *
     * @param  array<string, mixed>  $scope
     */
    public function resolve(mixed $value, array $scope): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $k => $v) {
                $resolved[$k] = $this->resolve($v, $scope);
            }

            return $resolved;
        }

        if (! is_string($value) || ! str_contains($value, '{{')) {
            return $value;
        }

        if (preg_match(self::SINGLE, $value, $m) && substr_count($value, '{{') === 1) {
            return $this->evaluate($m[1], $scope);
        }

        return preg_replace_callback(self::ANY, fn (array $m) => $this->stringify($this->evaluate($m[1], $scope)), $value);
    }

    public function containsExpression(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($this->containsExpression($v)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && str_contains($value, '{{');
    }

    /**
     * Returns null when every expression in the value is syntactically valid,
     * otherwise a human-readable error.
     */
    public function syntaxError(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($error = $this->syntaxError($v)) {
                    return $error;
                }
            }

            return null;
        }

        if (! is_string($value) || ! str_contains($value, '{{')) {
            return null;
        }

        if (substr_count($value, '{{') !== substr_count($value, '}}')) {
            return 'Unbalanced {{ }} braces.';
        }

        preg_match_all(self::ANY, $value, $matches);

        foreach ($matches[1] as $expression) {
            try {
                $this->parse($expression);
            } catch (ExpressionException $e) {
                return $e->getMessage();
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    public function evaluate(string $expression, array $scope): mixed
    {
        [$head, $filters] = $this->parse($expression);

        $value = $this->term($head, $scope);

        foreach ($filters as [$name, $args]) {
            $value = $this->applyFilter($name, $value, array_map(fn ($a) => $this->term($a, $scope), $args));
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: list<array{0: string, 1: list<string>}>}
     */
    private function parse(string $expression): array
    {
        $parts = $this->splitOutside(trim($expression), '|');
        $head = trim(array_shift($parts));

        if ($head === '') {
            throw new ExpressionException('Empty expression.');
        }

        $this->assertTerm($head);
        $filters = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (! preg_match('/^([a-z_]+)\s*(?:\((.*)\))?$/s', $part, $m)) {
                throw new ExpressionException("Invalid filter syntax: {$part}");
            }
            if (! in_array($m[1], self::FILTERS, true)) {
                throw new ExpressionException("Unknown filter: {$m[1]}");
            }
            $args = isset($m[2]) && trim($m[2]) !== ''
                ? array_map('trim', $this->splitOutside($m[2], ','))
                : [];
            foreach ($args as $arg) {
                $this->assertTerm($arg);
            }
            $filters[] = [$m[1], $args];
        }

        return [$head, $filters];
    }

    private function assertTerm(string $term): void
    {
        if ($this->isLiteral($term)) {
            return;
        }

        if (! preg_match('/^[A-Za-z_]\w*(?:\.\w+|\[\d+\]|\[(["\'])[^"\']*\1\])*$/', $term)) {
            throw new ExpressionException("Invalid path: {$term}");
        }

        $root = strtok($term, '.[');
        if (! in_array($root, self::ROOTS, true)) {
            throw new ExpressionException("Unknown root \"{$root}\". Allowed: ".implode(', ', self::ROOTS));
        }
    }

    private function isLiteral(string $term): bool
    {
        return (bool) preg_match('/^(?:"[^"]*"|\'[^\']*\'|-?\d+(?:\.\d+)?|true|false|null)$/', $term);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function term(string $term, array $scope): mixed
    {
        if ($this->isLiteral($term)) {
            return match (true) {
                $term === 'true' => true,
                $term === 'false' => false,
                $term === 'null' => null,
                is_numeric($term) => str_contains($term, '.') ? (float) $term : (int) $term,
                default => substr($term, 1, -1),
            };
        }

        if ($term === 'now') {
            return CarbonImmutable::now()->toIso8601String();
        }

        preg_match_all('/\w+|\[(\d+)\]|\[(["\'])([^"\']*)\2\]/', $term, $m, PREG_SET_ORDER);
        $value = $scope;

        foreach ($m as $segment) {
            $key = match (true) {
                isset($segment[3]) && $segment[3] !== '' => $segment[3],
                ($segment[1] ?? '') !== '' => (int) $segment[1],
                default => $segment[0],
            };

            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $args
     */
    private function applyFilter(string $name, mixed $value, array $args): mixed
    {
        $str = fn () => $this->stringify($value);

        return match ($name) {
            'upper' => Str::upper($str()),
            'lower' => Str::lower($str()),
            'title' => Str::title($str()),
            'trim' => trim($str()),
            'slug' => Str::slug($str()),
            'default' => ($value === null || $value === '' || $value === []) ? ($args[0] ?? null) : $value,
            'json' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'length', 'count' => is_array($value) ? count($value) : mb_strlen($str()),
            'first' => is_array($value) ? (array_values($value)[0] ?? null) : null,
            'last' => is_array($value) && $value !== [] ? array_values($value)[count($value) - 1] : null,
            'join' => is_array($value) ? implode((string) ($args[0] ?? ', '), array_map(fn ($v) => $this->stringify($v), $value)) : $str(),
            'round' => round((float) $value, (int) ($args[0] ?? 0)),
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => $str(),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'not' => ! $value,
            'date' => $this->date($value)?->format((string) ($args[0] ?? 'Y-m-d')),
            'add_days' => $this->date($value)?->addDays((int) ($args[0] ?? 0))->toIso8601String(),
            'sub_days' => $this->date($value)?->subDays((int) ($args[0] ?? 0))->toIso8601String(),
            'keys' => is_array($value) ? array_keys($value) : [],
            'pluck' => is_array($value) ? array_values(array_map(fn ($row) => is_array($row) ? ($row[$args[0] ?? ''] ?? null) : null, $value)) : [],
            'sum' => is_array($value) ? array_sum(array_map('floatval', array_filter($value, 'is_numeric'))) : 0,
            'slice' => is_array($value) ? array_slice($value, (int) ($args[0] ?? 0), isset($args[1]) ? (int) $args[1] : null) : mb_substr($str(), (int) ($args[0] ?? 0), isset($args[1]) ? (int) $args[1] : null),
            'replace' => str_replace((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), $str()),
            'truncate' => Str::limit($str(), (int) ($args[0] ?? 100)),
            'split' => $value === null ? [] : array_map('trim', explode($this->separator($args[0] ?? null), $str())),
            default => throw new ExpressionException("Unknown filter: {$name}"),
        };
    }

    /**
     * @return non-empty-string
     */
    private function separator(mixed $value): string
    {
        $separator = is_scalar($value) ? (string) $value : '';

        return $separator === '' ? ',' : $separator;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    public function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * Split on a delimiter, ignoring delimiters inside quotes or parentheses.
     *
     * @return list<string>
     */
    private function splitOutside(string $input, string $delimiter): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $depth = 0;

        foreach (mb_str_split($input) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
                $buffer .= $char;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === $delimiter && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if ($quote !== null || $depth !== 0) {
            throw new ExpressionException('Unterminated quote or parenthesis in expression.');
        }

        $parts[] = $buffer;

        return $parts;
    }
}
