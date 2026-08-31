<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;

final class YnabSplitReconstructor
{
    private const string SPLIT_MEMO_PATTERN = '/^Split\s*\(?\s*(\d+)\s*\/\s*(\d+)\s*\)?$/i';

    public function isSplitMemo(string $memo): bool
    {
        return $this->splitPosition($memo) !== null;
    }

    /**
     * @return array{0: int, 1: int}|null The leg's own 1-based position and the group's leg count.
     */
    public function splitPosition(string $memo): ?array
    {
        $matches = [];
        if (preg_match(self::SPLIT_MEMO_PATTERN, trim($memo), $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
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
        $bufferTotal = 0;
        $bufferNext = 0;

        foreach ($rows as $index => $row) {
            $position = $this->splitPosition($row['Memo'] ?? '');
            $key = ($row['Account'] ?? '').'|'.($row['Date'] ?? '').'|'.($row['Payee'] ?? '');

            // The memo's own "n of m" is the only row identity this export
            // carries. Without it two splits back to back at one payee and date
            // share a natural key and collapse into one transaction.
            $continues = $position !== null
                && $key === $bufferKey
                && $position[1] === $bufferTotal
                && $position[0] === $bufferNext;

            if ($continues) {
                $buffer[] = $index;
                $bufferNext++;

                continue;
            }

            if (count($buffer) > 1) {
                $groups[] = $buffer;
            }

            if ($position !== null) {
                $buffer = [$index];
                $bufferKey = $key;
                $bufferTotal = $position[1];
                $bufferNext = $position[0] + 1;
            } else {
                $buffer = [];
                $bufferKey = null;
                $bufferTotal = 0;
                $bufferNext = 0;
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
    public function assertLegsPresent(array $legAmountsMinor): void
    {
        // A zero-net group is NOT checked here: legs that cancel are how a
        // reclassification between two categories is written, and one such row
        // used to cost the reader every other row in the export.
        if ($legAmountsMinor === []) {
            throw new UnrecognizedMigrationFileException('reconstructed split group has zero legs');
        }
    }
}
