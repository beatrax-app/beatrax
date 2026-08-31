<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Presentation;

use Modules\Chains\Internal\Enums\SettlementToleranceUsed;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\ValueObjects\Money;

// Turns a hint's evidence JSON into the lines the review queue shows under
// it. Each kind writes a different set of keys, so the per-kind readers are
// separate rather than one chain of elseif over one shared bag.
final class HintEvidenceSummary
{
    private const string LANG_PREFIX = 'chains::hints.evidence.';

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
            ChainLinkKind::IcsBulkSettle->value => self::bulkSettleLines($evidence, $currency),
            ChainLinkKind::FundedByCardHint->value => self::cardLines($evidence),
            ChainLinkKind::RefundOfHint->value => self::refundLines($evidence),
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

        $tolerance = self::toleranceUsed($evidence);
        if ($tolerance !== null) {
            $lines[] = Lang::get(self::LANG_PREFIX.'tolerance', [
                'tolerance' => Lang::get(self::LANG_PREFIX.'tolerance_used.'.$tolerance->value),
            ]);
        }

        $delta = $evidence['unaccounted_delta_minor'] ?? null;
        if (is_numeric($delta)) {
            $lines[] = self::deltaLine((int) $delta, $currency);
        }

        $covered = $evidence['covered_count'] ?? null;
        if (is_numeric($covered)) {
            $lines[] = Lang::get(self::LANG_PREFIX.'covered', ['count' => Fmt::number((int) $covered)]);
        }

        $statement = $evidence['statement_id'] ?? null;
        if (is_numeric($statement)) {
            $lines[] = Lang::get(self::LANG_PREFIX.'statement', ['id' => (string) (int) $statement]);
        }

        return $lines;
    }

    /**
     * @param  array<mixed>  $evidence
     */
    private static function toleranceUsed(array $evidence): ?SettlementToleranceUsed
    {
        $stored = $evidence['tolerance_used'] ?? null;

        return is_string($stored) ? SettlementToleranceUsed::tryFrom($stored) : null;
    }

    // The stored delta is signed against the resolver's convention (positive =
    // the reader paid more than the statement asked). Rendering it signed made
    // the reader carry that convention; the sign picks the sentence instead.
    private static function deltaLine(int $deltaMinor, string $currency): string
    {
        if ($deltaMinor === 0) {
            return Lang::get(self::LANG_PREFIX.'delta_balanced');
        }

        $amount = Money::ofMinor(abs($deltaMinor), $currency)->format();

        return $deltaMinor > 0
            ? Lang::get(self::LANG_PREFIX.'delta_overpaid', ['amount' => $amount])
            : Lang::get(self::LANG_PREFIX.'delta_underpaid', ['amount' => $amount]);
    }

    /**
     * @param  array<mixed>  $evidence
     * @return list<string>
     */
    private static function cardLines(array $evidence): array
    {
        $lastFour = $evidence['card_last4'] ?? null;

        return is_string($lastFour) && $lastFour !== ''
            ? [Lang::get(self::LANG_PREFIX.'card_last4', ['last4' => $lastFour])]
            : [];
    }

    /**
     * @param  array<mixed>  $evidence
     * @return list<string>
     */
    private static function refundLines(array $evidence): array
    {
        $reference = $evidence['original_reference_id'] ?? null;

        return is_string($reference) && $reference !== ''
            ? [Lang::get(self::LANG_PREFIX.'original_reference', ['reference' => $reference])]
            : [];
    }
}
