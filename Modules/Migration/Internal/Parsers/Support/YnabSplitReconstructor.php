<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

/**
 * Reconstructs YNAB4/nYNAB split-leg groups from the `"Split (n/m)"` memo
 * convention (RESEARCH.md Pitfall 2) — the CSV export carries no explicit
 * parent/child link column, only consecutive rows sharing the same
 * (Account, Date, Payee) whose `Memo` matches the Split convention.
 *
 * Deliberately conservative: only rows that are BOTH memo-tagged AND
 * adjacent under the same (Account, Date, Payee) key collapse into a group.
 * A lone memo-tagged row (group size 1) is left ungrouped — a genuine split
 * always has >=2 legs, so a singleton is more likely a coincidental "Split"
 * substring in an otherwise plain memo than a real reconstruction target.
 */
final class YnabSplitReconstructor
{
    private const SPLIT_MEMO_PATTERN = '/^Split\s*\(?\s*\d+\s*\/\s*\d+\s*\)?$/i';

    public function isSplitMemo(string $memo): bool
    {
        return preg_match(self::SPLIT_MEMO_PATTERN, trim($memo)) === 1;
    }

    /**
     * Groups consecutive same-(Account, Date, Payee) split-memo rows.
     *
     * @param  array<int, array<string, string>>  $rows  Register rows, in file order, 0-indexed.
     * @return list<list<int>> Each entry is an ordered list of row indexes belonging to one split group (size >= 2).
     */
    public function groupSplitRows(array $rows): array
    {
        $groups = [];
        $buffer = [];
        $bufferKey = null;

        foreach ($rows as $index => $row) {
            $isSplit = $this->isSplitMemo($row['Memo'] ?? '');
            $key = ($row['Account'] ?? '').'|'.($row['Date'] ?? '').'|'.($row['Payee'] ?? '');

            if ($isSplit && $key === $bufferKey) {
                $buffer[] = $index;

                continue;
            }

            if (count($buffer) > 1) {
                $groups[] = $buffer;
            }

            if ($isSplit) {
                $buffer = [$index];
                $bufferKey = $key;
            } else {
                $buffer = [];
                $bufferKey = null;
            }
        }

        if (count($buffer) > 1) {
            $groups[] = $buffer;
        }

        return $groups;
    }

    /**
     * Sum-sanity guard (Pitfall 2): a reconstructed group must have at least
     * one leg and the legs must not net to zero (a degenerate/miscategorized
     * grouping). A full running-balance cross-check needs an adjacent
     * non-split anchor row this class does not have visibility into — this
     * guard catches the structurally-impossible cases (empty group, legs
     * that exactly cancel) without over-claiming a stronger proof.
     *
     * @param  list<int>  $legAmountsMinor  Signed minor amounts, one per leg.
     */
    public function assertSumSane(array $legAmountsMinor): void
    {
        if ($legAmountsMinor === []) {
            throw new UnrecognizedMigrationFileException('reconstructed split group has zero legs');
        }

        if (array_sum($legAmountsMinor) === 0) {
            throw new UnrecognizedMigrationFileException('reconstructed split group legs sum to zero — refusing to treat as a valid split');
        }
    }
}
