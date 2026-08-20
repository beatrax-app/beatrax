<?php

declare(strict_types=1);

use Modules\Core\Internal\Console\Support\BackupRetentionPolicy;

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
        // 7 newest dailies: 04-19 … 05-19. The 4 most-recent Sundays overall
        // (04-26, 05-03, 05-10, 05-17) are already in that set, so 04-12 — the
        // 5th-most-recent Sunday — is pruned alongside 03-29 and 04-05.
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
        // 2026-13-99 passes the digit regex but is not a real calendar date. It
        // is skipped from the Sunday scan, yet the 7-daily window sorts on the
        // raw date_key string, so it sorts above 2026-05-19 and is kept. The
        // promise is that the sweep never throws, not that it prunes such rows.
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
