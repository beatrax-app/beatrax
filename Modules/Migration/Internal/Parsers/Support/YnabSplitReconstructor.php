<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

final class YnabSplitReconstructor
{
    private const SPLIT_MEMO_PATTERN = '/^Split\s*\(?\s*\d+\s*\/\s*\d+\s*\)?$/i';

    public function isSplitMemo(string $memo): bool
    {
        return preg_match(self::SPLIT_MEMO_PATTERN, trim($memo)) === 1;
    }

    /**
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
     * @param  list<int>  $legAmountsMinor  Signed minor amounts, one per leg.
     */
    public function assertSumSane(array $legAmountsMinor): void
    {
        // Catches only the structurally-impossible cases: a full running-balance
        // cross-check needs an adjacent non-split anchor row this class cannot see.
        if ($legAmountsMinor === []) {
            throw new UnrecognizedMigrationFileException('reconstructed split group has zero legs');
        }

        if (array_sum($legAmountsMinor) === 0) {
            throw new UnrecognizedMigrationFileException('reconstructed split group legs sum to zero — refusing to treat as a valid split');
        }
    }
}
