<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Services\Concerns;

trait SummarizesRuleConditions
{
    private function summarizeConditions(mixed $decoded): string
    {
        $parts = [];
        foreach ($this->extractConditionList($decoded) as $condition) {
            if (is_array($condition)) {
                $parts[] = $this->describeCondition($condition);
            }
        }

        return $parts === [] ? 'no rule conditions recorded' : implode('; ', $parts);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function extractConditionList(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['conditions']) && is_array($decoded['conditions'])) {
            return $decoded['conditions'];
        }

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $condition
     */
    private function describeCondition(array $condition): string
    {
        $field = is_string($condition['field'] ?? null) ? $condition['field'] : '?';
        $op = is_string($condition['op'] ?? null) ? $condition['op'] : '?';
        $value = $condition['value'] ?? '?';
        $valueStr = is_scalar($value) ? (string) $value : '?';

        return "{$field} {$op} {$valueStr}";
    }
}
