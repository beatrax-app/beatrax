<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Support;

use Carbon\CarbonImmutable;
use Throwable;

final class BackupRetentionPolicy
{
    private const FILENAME_PATTERN = '/^beatrax-(\d{4})-(\d{2})-(\d{2})-(\d{6})\.sqlite$/';

    private const DAILY_KEEP_COUNT = 7;

    private const SUNDAY_KEEP_COUNT = 4;

    /**
     * @param  list<string>  $candidateFilenames
     * @return list<string>
     */
    public function keepers(array $candidateFilenames): array
    {
        [$kept, $matched] = $this->partition($candidateFilenames);

        if ($matched === []) {
            return array_values($kept);
        }

        usort(
            $matched,
            static fn (array $a, array $b): int => strcmp($b['date_key'], $a['date_key']),
        );

        $keepIndexes = $this->dailyKeepIndexes($matched) + $this->sundayKeepIndexes($matched);

        foreach ($matched as $entry) {
            if (isset($keepIndexes[$entry['index']])) {
                $kept[$entry['index']] = $entry['name'];
            }
        }

        // Re-key in input order, not the DESC sort above, so output stays stable.
        ksort($kept);

        return array_values($kept);
    }

    // date_key is zero-padded and lexicographically sortable, which is what lets
    // the caller order it with strcmp instead of parsing dates.
    /**
     * @param  list<string>  $candidateFilenames
     * @return array{0: array<int, string>, 1: list<array{index: int, name: string, date_key: string, date_only: string}>}
     */
    private function partition(array $candidateFilenames): array
    {
        $kept = [];
        $matched = [];

        foreach ($candidateFilenames as $index => $name) {
            if (preg_match(self::FILENAME_PATTERN, $name, $m) !== 1) {
                // .suspect, pre-restore-* and .meta.json are always preserved.
                $kept[$index] = $name;

                continue;
            }

            $matched[] = [
                'index' => $index,
                'name' => $name,
                'date_key' => $m[1].'-'.$m[2].'-'.$m[3].' '.$m[4],
                'date_only' => $m[1].'-'.$m[2].'-'.$m[3],
            ];
        }

        return [$kept, $matched];
    }

    /**
     * @param  list<array{index: int, name: string, date_key: string, date_only: string}>  $matched
     * @return array<int, true>
     */
    private function dailyKeepIndexes(array $matched): array
    {
        $indexes = [];
        foreach (array_slice($matched, 0, self::DAILY_KEEP_COUNT) as $entry) {
            $indexes[$entry['index']] = true;
        }

        return $indexes;
    }

    // The 4 most-recent Sunday-dated files. The regex accepts digit-shaped
    // components without calendar validity, so a bogus date would crash
    // CarbonImmutable::parse() — skip it as non-Sunday rather than halt the sweep.
    /**
     * @param  list<array{index: int, name: string, date_key: string, date_only: string}>  $matched
     * @return array<int, true>
     */
    private function sundayKeepIndexes(array $matched): array
    {
        // Read from the constant so a Carbon major that bumps the value still works.
        $sundayDow = CarbonImmutable::SUNDAY;

        $indexes = [];
        $sundayCount = 0;
        foreach ($matched as $entry) {
            if ($sundayCount >= self::SUNDAY_KEEP_COUNT) {
                break;
            }
            try {
                $dow = CarbonImmutable::parse($entry['date_only'])->dayOfWeek;
            } catch (Throwable) {
                continue;
            }
            if ($dow === $sundayDow) {
                $indexes[$entry['index']] = true;
                $sundayCount++;
            }
        }

        return $indexes;
    }
}
