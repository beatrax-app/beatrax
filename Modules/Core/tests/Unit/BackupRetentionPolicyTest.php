<?php

declare(strict_types=1);

use Modules\Core\Internal\Console\Support\BackupRetentionPolicy;

/*
 * Pure-logic specification for the retention rule that decides which
 * `storage/app/backups/*.sqlite` files the scheduled `db:backup`
 * command should keep after a successful new backup is written. The
 * rule preserves the 7 most-recent timestamped daily files PLUS the
 * 4 most-recent Sunday-dated weekly files, and always passes through
 * any filename that does not match the `beatrax-YYYY-MM-DD-HHMMSS.sqlite`
 * shape (so `.suspect` files, `pre-restore-*` snapshots, and
 * `.meta.json` sidecars survive every prune).
 *
 * The policy is intentionally pure (no Filesystem dependency) so the
 * dataset below proves every relevant edge case in-memory without
 * touching disk.
 */

dataset('retention scenarios', [
    'no candidates yields empty keep list' => [
        'candidates' => [],
        'now' => '2026-05-19 03:00:00',
        'expectedKeepers' => [],
    ],

    '5 daily files under the 7-daily cap keeps all of them' => [
        'candidates' => [
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite', // Sunday
            'beatrax-2026-05-18-030000.sqlite',
        ],
        'now' => '2026-05-19 03:00:00',
        'expectedKeepers' => [
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite',
            'beatrax-2026-05-18-030000.sqlite',
        ],
    ],

    '14 consecutive daily files: 7 newest dailies plus 2 Sundays among older 7' => [
        // 2026-05-19 is a Tuesday; 14 days back: 2026-05-06 .. 2026-05-19.
        // Sundays in that window: 2026-05-10 and 2026-05-17.
        'candidates' => [
            'beatrax-2026-05-06-030000.sqlite',
            'beatrax-2026-05-07-030000.sqlite',
            'beatrax-2026-05-08-030000.sqlite',
            'beatrax-2026-05-09-030000.sqlite',
            'beatrax-2026-05-10-030000.sqlite', // Sunday
            'beatrax-2026-05-11-030000.sqlite',
            'beatrax-2026-05-12-030000.sqlite',
            'beatrax-2026-05-13-030000.sqlite',
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite', // Sunday
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
        'now' => '2026-05-19 04:00:00',
        // 7 newest dailies: 2026-05-13 .. 2026-05-19 (already includes 05-17 Sunday).
        // Sunday weekly extras: 2026-05-10 (the only Sunday outside the 7-daily window).
        'expectedKeepers' => [
            'beatrax-2026-05-10-030000.sqlite',
            'beatrax-2026-05-13-030000.sqlite',
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite',
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
    ],

    '8 weeks of Sundays plus a couple of weekday dailies: 7 newest dailies + 4 most-recent Sundays' => [
        // Sundays: 2026-03-29, 04-05, 04-12, 04-19, 04-26, 05-03, 05-10, 05-17.
        // Weekday dailies: 2026-05-18 (Mon) and 2026-05-19 (Tue).
        'candidates' => [
            'beatrax-2026-03-29-030000.sqlite',
            'beatrax-2026-04-05-030000.sqlite',
            'beatrax-2026-04-12-030000.sqlite',
            'beatrax-2026-04-19-030000.sqlite',
            'beatrax-2026-04-26-030000.sqlite',
            'beatrax-2026-05-03-030000.sqlite',
            'beatrax-2026-05-10-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite',
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
        'now' => '2026-05-19 04:00:00',
        // 7 newest dailies: 04-19, 04-26, 05-03, 05-10, 05-17, 05-18, 05-19.
        // 4 most-recent Sundays: 04-26, 05-03, 05-10, 05-17 (already in 7-daily set).
        // Older Sundays NOT in the keep list: 03-29, 04-05.
        // But 04-12 IS one of the 4 most-recent if we only count Sundays
        // OUTSIDE the 7-daily window? The plan says: "PLUS the 4 most-recent
        // Sunday-dated weekly files" — the rule selects the 4 most-recent
        // Sundays from ALL matching files, then unions with the 7-daily set.
        // 4 most-recent Sundays overall = 04-26, 05-03, 05-10, 05-17 — all
        // already kept. Pruning targets: 03-29, 04-05, 04-12, 04-19? No:
        // 04-19 is one of the 7 newest dailies. So pruned = 03-29 + 04-05.
        // Kept = 04-12 only if it lands in the "4 most-recent Sundays" OR
        // the 7-daily set. It is NOT in 7-daily (only 04-19 onward); it's
        // the 5th-most-recent Sunday. So 04-12 should be PRUNED.
        'expectedKeepers' => [
            'beatrax-2026-04-19-030000.sqlite',
            'beatrax-2026-04-26-030000.sqlite',
            'beatrax-2026-05-03-030000.sqlite',
            'beatrax-2026-05-10-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite',
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
    ],

    'two same-day timestamps both count as separate dailies' => [
        // Same-day pair: 03:00 and 15:30. Plus 6 other dailies. Total 8.
        // 7 newest by date+time descending should keep both same-day rows.
        'candidates' => [
            'beatrax-2026-05-12-030000.sqlite',
            'beatrax-2026-05-13-030000.sqlite',
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite', // Sunday
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
            'beatrax-2026-05-19-153000.sqlite',
        ],
        'now' => '2026-05-19 16:00:00',
        // 7 newest by date+time desc: 05-19 15:30, 05-19 03:00, 05-18, 05-17, 05-16, 05-15, 05-14.
        // 4 most-recent Sundays: only 05-17 in this list — already kept via daily.
        // Pruned: 05-12, 05-13.
        'expectedKeepers' => [
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite',
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
            'beatrax-2026-05-19-153000.sqlite',
        ],
    ],

    'calendar-invalid digit-shaped date is treated as non-Sunday without crashing the sweep' => [
        // 2026-13-99 passes the digit regex but is not a real calendar
        // date. The policy must not crash on CarbonImmutable::parse():
        // calendar-invalid entries are skipped from the Sunday-keep
        // scan and never promoted into the keep set on their own. They
        // ARE still subject to the 7-daily window, which sorts by the
        // raw date_key string — so the calendar-invalid entry sorts
        // lexicographically (2026-13-99 > 2026-05-19) and lands in the
        // 7-daily slot. The promise the fix makes is "the sweep never
        // throws", not "calendar-invalid entries are pruned aggressively".
        'candidates' => [
            'beatrax-2026-13-99-250000.sqlite',
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite', // Sunday
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
        'now' => '2026-05-19 04:00:00',
        // 7 newest by lexicographic date_key: 2026-13-99 wins, then
        // 05-14 .. 05-19. All seven kept.
        'expectedKeepers' => [
            'beatrax-2026-13-99-250000.sqlite',
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite',
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
    ],

    'suspect + pre-restore + meta.json files are always passed through unchanged' => [
        'candidates' => [
            'beatrax-2026-05-09-030000.sqlite.suspect',
            'pre-restore-2026-05-11-120000.sqlite',
            'beatrax-2026-05-12-030000.sqlite',
            'beatrax-2026-05-12-030000.sqlite.meta.json',
            'beatrax-2026-05-13-030000.sqlite',
            'beatrax-2026-05-13-030000.sqlite.meta.json',
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-14-030000.sqlite.meta.json',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite', // Sunday
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
        'now' => '2026-05-19 04:00:00',
        // 7 newest dailies: 05-13 .. 05-19. The 05-12 matching file is the
        // 8th, so it gets pruned (no Sunday rescue — 05-12 is Tuesday).
        // .suspect / pre-restore / .meta.json are always kept (non-matching
        // filenames pass through), even when their underlying daily was pruned.
        'expectedKeepers' => [
            'beatrax-2026-05-09-030000.sqlite.suspect',
            'pre-restore-2026-05-11-120000.sqlite',
            'beatrax-2026-05-12-030000.sqlite.meta.json',
            'beatrax-2026-05-13-030000.sqlite',
            'beatrax-2026-05-13-030000.sqlite.meta.json',
            'beatrax-2026-05-14-030000.sqlite',
            'beatrax-2026-05-14-030000.sqlite.meta.json',
            'beatrax-2026-05-15-030000.sqlite',
            'beatrax-2026-05-16-030000.sqlite',
            'beatrax-2026-05-17-030000.sqlite',
            'beatrax-2026-05-18-030000.sqlite',
            'beatrax-2026-05-19-030000.sqlite',
        ],
    ],
]);

it('keeps 7 most-recent dailies plus 4 most-recent Sundays plus all non-matching filenames', function (array $candidates, string $now, array $expectedKeepers): void {
    $policy = new BackupRetentionPolicy;
    $keepers = $policy->keepers($candidates);

    $sortedKeepers = $keepers;
    sort($sortedKeepers);
    $sortedExpected = $expectedKeepers;
    sort($sortedExpected);

    expect($sortedKeepers)->toBe($sortedExpected);
})->with('retention scenarios');
