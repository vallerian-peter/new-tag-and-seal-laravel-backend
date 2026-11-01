<?php

namespace App\Services\Dynamic;

class RuleEvaluator
{
    public function evaluate(?array $rule, array $facts): bool
    {
        if ($rule === null || $rule === []) {
            return true;
        }
        if (isset($rule['all'])) {
            return collect($rule['all'])->every(fn ($child) => $this->evaluate($child, $facts));
        }
        if (isset($rule['any'])) {
            return collect($rule['any'])->contains(fn ($child) => $this->evaluate($child, $facts));
        }
        if (isset($rule['not'])) {
            return ! $this->evaluate($rule['not'], $facts);
        }

        $actual = data_get($facts, $rule['fact'] ?? '');
        $expected = $rule['value'] ?? null;

        return match ($rule['operator'] ?? null) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
            'greater_than' => is_numeric($actual) && $actual > $expected,
            'greater_than_or_equal' => is_numeric($actual) && $actual >= $expected,
            'less_than' => is_numeric($actual) && $actual < $expected,
            'less_than_or_equal' => is_numeric($actual) && $actual <= $expected,
            'exists' => $actual !== null,
            default => false,
        };
    }
}
