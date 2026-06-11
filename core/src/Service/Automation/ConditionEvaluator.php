<?php
declare(strict_types=1);

namespace App\Service\Automation;

/**
 * Evaluates a declarative condition (JSON) against a context (event payload)
 * (program tier-2, P12). Pure/no side effects → easily testable.
 *
 * Structure:
 *   - `{}`                                → always true (no condition)
 *   - `{"all":[…]}` / `{"any":[…]}` / `{"not":{…}}`
 *   - leaf: `{"field":"data.priority","op":"eq","value":"high"}`
 * Operators: eq, ne, gt, lt, gte, lte, contains, in, exists.
 * Field paths address the context via dot notation (`data.priority`).
 */
class ConditionEvaluator
{
    /**
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $context
     */
    public function evaluate(array $condition, array $context): bool
    {
        if ($condition === []) {
            return true;
        }
        if (isset($condition['all'])) {
            foreach ((array)$condition['all'] as $c) {
                if (!$this->evaluate((array)$c, $context)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($condition['any'])) {
            foreach ((array)$condition['any'] as $c) {
                if ($this->evaluate((array)$c, $context)) {
                    return true;
                }
            }

            return false;
        }
        if (isset($condition['not'])) {
            return !$this->evaluate((array)$condition['not'], $context);
        }

        $actual = $this->resolve((string)($condition['field'] ?? ''), $context);

        return $this->compare($actual, (string)($condition['op'] ?? 'eq'), $condition['value'] ?? null);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function resolve(string $path, array $context): mixed
    {
        if ($path === '') {
            return null;
        }
        $value = $context;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }

    private function compare(mixed $actual, string $op, mixed $value): bool
    {
        // Strict comparisons (===/!==, in_array strict): no PHP type-juggling
        // false matches (e.g. "high"==true, ""==null, 100=="1e2") that would
        // otherwise let rules fire erroneously on manipulated payloads.
        return match ($op) {
            'eq' => $actual === $value,
            'ne' => $actual !== $value,
            'gt' => is_numeric($actual) && is_numeric($value) && $actual > $value,
            'lt' => is_numeric($actual) && is_numeric($value) && $actual < $value,
            'gte' => is_numeric($actual) && is_numeric($value) && $actual >= $value,
            'lte' => is_numeric($actual) && is_numeric($value) && $actual <= $value,
            'contains' => is_string($actual) && is_string($value) && str_contains($actual, $value),
            'in' => is_array($value) && in_array($actual, $value, true),
            'exists' => $value ? $actual !== null : $actual === null,
            default => false,
        };
    }
}
