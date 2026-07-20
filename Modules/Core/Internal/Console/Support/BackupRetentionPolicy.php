<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pure-logic policy for the `storage/app/backups/` retention sweep
 * that runs at the end of a successful `db:backup` invocation.
 *
 * The rule:
 *
 *   1. Keep every basename that does NOT match the
 *      `beatrax-YYYY-MM-DD-HHMMSS.sqlite` shape (passes through
 *      `.suspect` files, `pre-restore-*` snapshots, `.meta.json`
 *      sidecars, and any operator-dropped artifact verbatim).
 *
 *   2. From the matching basenames, keep:
 *      - the 7 most-recent files by parsed date+time (descending), AND
 *      - the 4 most-recent files whose parsed date falls on a Sunday.
 *
 *   3. Anything else from the matching set is OMITTED from the return
 *      value — the caller is responsible for deleting those files.
 *
 * The class is intentionally pure: it accepts an array of basename
 * strings + a clock reading and returns the subset to KEEP. There is
 * no `Filesystem` dependency, no I/O, no globbing. The consuming
 * `BackupDatabaseCommand` reads the directory listing, hands the
 * basenames in, then issues `delete()` calls against the omitted
 * entries. The split keeps the policy fully unit-testable and the
 * command's I/O surface narrow.
 *
 * The class is declared as an instance method (rather than a static
 * helper like `AmountStringParser`) so it can be constructor-DI'd into
 * the command — keeps the consumer's dependencies uniform with the
 * rest of the project's DI-only posture.
 */
final class BackupRetentionPolicy
{
    /**
     * Matches a scheduled-backup basename. Captures: 1=Y, 2=m, 3=d, 4=HHMMSS.
     */
    private const FILENAME_PATTERN = '/^beatrax-(\d{4})-(\d{2})-(\d{2})-(\d{6})\.sqlite$/';

    private const DAILY_KEEP_COUNT = 7;

    private const SUNDAY_KEEP_COUNT = 4;

    /**
     * Returns the subset of `$candidateFilenames` that the caller MUST
     * keep on disk. Inputs are basename strings (the caller strips the
     * directory portion before calling).
     *
     * Output order mirrors the original input order — keeps callers'
     * downstream iteration stable for logging.
     *
     * @param  list<string>  $candidateFilenames
     * @return list<string>
     */
    public function keepers(array $candidateFilenames, CarbonImmutable $now): array
    {
        // Sunday's day-of-week constant on Carbon equals 0. Read from the
        // class constant rather than hardcoding so a future Carbon major
        // version that bumps the value still resolves correctly.
        $sundayDow = CarbonImmutable::SUNDAY;

        $kept = [];
        $matched = [];

        foreach ($candidateFilenames as $index => $name) {
            if (preg_match(self::FILENAME_PATTERN, $name, $m) !== 1) {
                // Non-matching filenames (e.g. .suspect, pre-restore-*,
                // .meta.json) are always preserved.
                $kept[$index] = $name;

                continue;
            }

            // Parse the date+time portion of the filename into a sortable
            // key. Format: YYYY-MM-DD HHMMSS. Sorting lexicographically
            // works because all components are zero-padded fixed-width.
            $dateKey = $m[1].'-'.$m[2].'-'.$m[3].' '.$m[4];
            $dateOnly = $m[1].'-'.$m[2].'-'.$m[3];

            $matched[] = [
                'index' => $index,
                'name' => $name,
                'date_key' => $dateKey,
                'date_only' => $dateOnly,
            ];
        }

        if ($matched === []) {
            return array_values($kept);
        }

        usort(
            $matched,
            static fn (array $a, array $b): int => strcmp($b['date_key'], $a['date_key']),
        );

        $dailyKeepIndexes = [];
        foreach (array_slice($matched, 0, self::DAILY_KEEP_COUNT) as $entry) {
            $dailyKeepIndexes[$entry['index']] = true;
        }

        // Weekly: take the 4 most-recent Sunday-dated matched files. The
        // regex accepts digit-shaped components without calendar validity,
        // so a bogus date like 2026-13-99 would crash CarbonImmutable::parse()
        // — treat it as non-Sunday (skipped) rather than halting the sweep.
        $sundayKeepIndexes = [];
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
                $sundayKeepIndexes[$entry['index']] = true;
                $sundayCount++;
            }
        }

        foreach ($matched as $entry) {
            if (isset($dailyKeepIndexes[$entry['index']]) || isset($sundayKeepIndexes[$entry['index']])) {
                $kept[$entry['index']] = $entry['name'];
            }
        }

        // Re-key in original input order (not the DESC sort above) so the
        // output is stable for downstream logging.
        ksort($kept);

        return array_values($kept);
    }
}
