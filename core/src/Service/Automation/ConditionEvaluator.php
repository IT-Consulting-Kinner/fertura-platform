<?php
declare(strict_types=1);

namespace App\Service\Automation;

/**
 * Wertet eine deklarative Bedingung (JSON) gegen einen Kontext (Event-Nutzlast)
 * aus (Programm Tier-2, P12). Rein/ohne Seiteneffekte → gut testbar.
 *
 * Struktur:
 *   - `{}`                                → immer wahr (keine Bedingung)
 *   - `{"all":[…]}` / `{"any":[…]}` / `{"not":{…}}`
 *   - Blatt: `{"field":"data.priority","op":"eq","value":"high"}`
 * Operatoren: eq, ne, gt, lt, gte, lte, contains, in, exists.
 * Feldpfade adressieren den Kontext per Punktnotation (`data.priority`).
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
        // Strikte Vergleiche (===/!==, in_array strict): keine PHP-Typ-Juggling-
        // Fehltreffer (z. B. "high"==true, ""==null, 100=="1e2"), die Regeln
        // sonst auf manipulierten Nutzlasten fälschlich auslösen ließen.
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
