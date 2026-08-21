<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Services\Concerns;

use JsonException;

trait SummarizesRuleConditions
{
    private const MAX_JSON_BYTES = 65536;

    private const MAX_JSON_DEPTH = 20;

    private function boundedJsonDecode(string $json): mixed
    {
        // The blob is untrusted source content, so decoding is bounded by size
        // and depth and returns null rather than throwing.
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }

        try {
            return json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

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
