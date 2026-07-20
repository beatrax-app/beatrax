<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\DetectsStartingBalance;
use Modules\Import\Public\Dto\StartingBalanceCandidate;

/**
 * @link ../../../../.docs/features/import/architecture.md#starting-balance-detection
 */
final readonly class DetectStartingBalancesQuery
{
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
        if ($forAccount === []) {
            return [];
        }

        if (count($forAccount) === 1) {
            return [$forAccount[0]];
        }

        // 1. Earliest openingBalanceDate wins. Find the minimum date
        //    across the group; drop anything later.
        $earliestDate = null;
        foreach ($forAccount as $candidate) {
            if ($earliestDate === null || $candidate->openingBalanceDate < $earliestDate) {
                $earliestDate = $candidate->openingBalanceDate;
            }
        }

        $atEarliest = [];
        foreach ($forAccount as $candidate) {
            if ($candidate->openingBalanceDate === $earliestDate) {
                $atEarliest[] = $candidate;
            }
        }

        if (count($atEarliest) === 1) {
            return [$atEarliest[0]];
        }

        // 2. On a date tie, prefer canonical CAMT.053 over MT940 (CAMT
        //    carries an explicit <OpngBal> element; MT940 sometimes
        //    recomputes a running total).
        $camt053 = [];
        $other = [];
        foreach ($atEarliest as $candidate) {
            if ($candidate->sourceFormat === 'camt053') {
                $camt053[] = $candidate;
            } else {
                $other[] = $candidate;
            }
        }

        if (count($camt053) === 1 && $other !== []) {
            return [$camt053[0]];
        }

        if (count($camt053) === 0 && count($other) === 1) {
            return [$other[0]];
        }

        // CAMT-only with multiple winners — still tied on both date and
        // source: surface every remaining candidate.
        if (count($camt053) >= 2) {
            return $camt053;
        }

        // No CAMT in the tie and multiple non-CAMT candidates — still
        // tied: surface every remaining candidate.
        if (count($other) >= 2) {
            return $other;
        }

        // Fallback: every entry tied at the same date with no CAMT/
        // non-CAMT split possible — surface them all.
        return $atEarliest;
    }
}
