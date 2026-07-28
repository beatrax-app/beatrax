<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Presentation;

use Modules\Ledger\Public\ValueObjects\Money;

// Turns a hint's evidence JSON into the lines the review queue shows under
// it. Each kind writes a different set of keys, so the per-kind readers are
// separate rather than one chain of elseif over one shared bag.
/**
 * @link ../../../../.docs/features/chains/architecture.md
 */
final class HintEvidenceSummary
{
    /**
     * @return list<string>
     */
    public function forHint(string $kind, string $evidenceJson, string $currency): array
    {
        $evidence = $evidenceJson === '' ? null : json_decode($evidenceJson, true);
        if (! is_array($evidence)) {
            return [];
        }

        return match ($kind) {
            'ics_bulk_settle' => self::bulkSettleLines($evidence, $currency),
            'funded_by_card_hint' => self::cardLines($evidence),
            'refund_of_hint' => self::refundLines($evidence),
            default => [],
        };
    }

    /**
     * @param  array<mixed>  $evidence
     * @return list<string>
     */
    private static function bulkSettleLines(array $evidence, string $currency): array
    {
        $lines = [];

        $tolerance = $evidence['tolerance_used'] ?? null;
        if (is_string($tolerance) && $tolerance !== '') {
            $lines[] = 'Tolerance: '.$tolerance;
        }

        $delta = $evidence['unaccounted_delta_minor'] ?? null;
        if (is_numeric($delta)) {
            $lines[] = 'Unaccounted delta: '.Money::ofMinor((int) $delta, $currency)->format('en_US');
        }

        $covered = $evidence['covered_count'] ?? null;
        if (is_numeric($covered)) {
            $lines[] = 'Covered transactions: '.(int) $covered;
        }

        $statement = $evidence['statement_id'] ?? null;
        if (is_numeric($statement)) {
            $lines[] = 'Card statement #'.(int) $statement;
        }

        return $lines;
    }

    /**
     * @param  array<mixed>  $evidence
     * @return list<string>
     */
    private static function cardLines(array $evidence): array
    {
        $lastFour = $evidence['card_last_four'] ?? null;

        return is_string($lastFour) && $lastFour !== '' ? ['Card ending in '.$lastFour] : [];
    }

    /**
     * @param  array<mixed>  $evidence
     * @return list<string>
     */
    private static function refundLines(array $evidence): array
    {
        $reference = $evidence['original_reference_id'] ?? null;

        return is_string($reference) && $reference !== '' ? ['Original order ref: '.$reference] : [];
    }
}
