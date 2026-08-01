<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\DetectsStartingBalance;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Ingestion\Public\Enums\SourceFormat;

/**
 * @link ../../../../.docs/features/import/architecture.md#starting-balance-detection
 */
final readonly class DetectStartingBalancesQuery
{
    // CAMT.053 carries an explicit <OpngBal> element, so on a date tie it
    // is preferred over MT940's sometimes-recomputed running total.
    private const CAMT_FORMAT = SourceFormat::Camt053->value;

    /**
     * @param  iterable<DetectsStartingBalance>  $detectors  Per-source detectors bound under the `starting-balance.detector` container tag, in registration order.
     */
    public function __construct(
        private iterable $detectors,
    ) {}

    /**
     * @param  list<int>  $importRunIds
     * @return list<StartingBalanceCandidate>
     */
    public function collect(array $importRunIds, User $user): array
    {
        if ($importRunIds === []) {
            return [];
        }

        $allCandidates = [];
        foreach ($this->detectors as $detector) {
            foreach ($detector->detect($importRunIds, $user) as $candidate) {
                $allCandidates[] = $candidate;
            }
        }

        $byAccount = self::groupByAccount($allCandidates);

        $out = [];
        foreach ($byAccount as $forAccount) {
            foreach (self::pickWinner($forAccount) as $winner) {
                $out[] = $winner;
            }
        }

        return $out;
    }

    /**
     * @param  list<StartingBalanceCandidate>  $all
     * @return array<int, list<StartingBalanceCandidate>>
     */
    private static function groupByAccount(array $all): array
    {
        $byAccount = [];
        foreach ($all as $candidate) {
            $byAccount[$candidate->accountId][] = $candidate;
        }

        return $byAccount;
    }

    /**
     * @param  list<StartingBalanceCandidate>  $forAccount
     * @return list<StartingBalanceCandidate>
     */
    private static function pickWinner(array $forAccount): array
    {
        if (count($forAccount) < 2) {
            return $forAccount;
        }

        $atEarliest = self::earliestDated($forAccount);
        if (count($atEarliest) === 1) {
            return [$atEarliest[0]];
        }

        return self::breakDateTie($atEarliest);
    }

    /**
     * @param  list<StartingBalanceCandidate>  $forAccount
     * @return list<StartingBalanceCandidate>
     */
    private static function earliestDated(array $forAccount): array
    {
        $earliestDate = null;
        foreach ($forAccount as $candidate) {
            if ($earliestDate === null || $candidate->openingBalanceDate < $earliestDate) {
                $earliestDate = $candidate->openingBalanceDate;
            }
        }

        return array_values(array_filter(
            $forAccount,
            static fn (StartingBalanceCandidate $candidate): bool => $candidate->openingBalanceDate === $earliestDate,
        ));
    }

    /**
     * @param  list<StartingBalanceCandidate>  $atEarliest
     * @return list<StartingBalanceCandidate>
     */
    private static function breakDateTie(array $atEarliest): array
    {
        $camt053 = array_values(array_filter(
            $atEarliest,
            static fn (StartingBalanceCandidate $candidate): bool => $candidate->sourceFormat === self::CAMT_FORMAT,
        ));
        $other = array_values(array_filter(
            $atEarliest,
            static fn (StartingBalanceCandidate $candidate): bool => $candidate->sourceFormat !== self::CAMT_FORMAT,
        ));

        // A single canonical CAMT.053 wins outright; a single non-CAMT
        // wins only when no CAMT is present. Any remaining multi-way tie
        // surfaces every still-tied candidate for manual resolution.
        return match (true) {
            count($camt053) === 1 && $other !== [] => [$camt053[0]],
            $camt053 === [] && count($other) === 1 => [$other[0]],
            count($camt053) >= 2 => $camt053,
            count($other) >= 2 => $other,
            default => $atEarliest,
        };
    }
}
