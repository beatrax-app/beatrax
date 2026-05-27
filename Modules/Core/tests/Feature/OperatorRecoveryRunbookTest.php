<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

/*
 * Content arch test for the operator-facing runbook at
 * .docs/runbooks/operator-recovery.md.
 *
 * Two safety properties:
 *
 * (a) Required substrings present — the "## Backups" and
 *     "## Operator recovery" sections must contain the operator-facing
 *     headings, code references, and retention numbers. Future doc churn
 *     that drops any of them fails the test at PR time.
 *
 * (b) Forbidden substrings absent — no GSD-internal references
 *     (.planning/), no phase labels (Phase NN), no four-digit
 *     decision IDs (D-####). The `cp database.sqlite` warning must
 *     appear exactly once — and only inside the
 *     "### DO NOT cp database.sqlite" subsection that warns against
 *     the unsafe live-DB copy.
 *
 * The test reads the runbook once via the injected Filesystem,
 * comment-strips markdown comments (`<!-- … -->`), and then runs the
 * required + forbidden assertion loops.
 *
 * History note: this contract used to live in README.md (predating the
 * Plan 17-07 public-audience rewrite). The contract migrated alongside
 * the content; the README itself now points readers here for any
 * operational workflow beyond install + use.
 */

it('preserves the operator-facing contract of operator-recovery.md backups + recovery sections', function (): void {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $runbookPath = base_path('.docs/runbooks/operator-recovery.md');

    expect($files->exists($runbookPath))->toBeTrue('.docs/runbooks/operator-recovery.md is expected at the canonical path.');

    $raw = $files->get($runbookPath);
    // Strip HTML/markdown comments so `<!-- D-1101 ... -->` blocks
    // would not trip the forbidden-substring assertions if they were
    // ever added by mistake. The `/s` flag makes `.` cross newlines.
    $contents = preg_replace('#<!--.*?-->#s', '', $raw) ?? $raw;

    // (a) Required substrings — every entry must occur at least once
    //     in the comment-stripped runbook.
    $required = [
        '## Backups',
        '## Operator recovery',
        'php artisan db:backup',
        'php artisan db:restore',
        'php artisan beatrax:doctor',
        'php artisan beatrax:failed-jobs',
        'pre-restore-',
        '.suspect',
        '7 daily',
        'Sunday',
        'Stuck Redis unique-lock keys',
        '### Restoring from a backup',
        '### Corrupt-backup alert',
        '### Failed-jobs maintenance',
    ];

    foreach ($required as $needle) {
        expect(str_contains($contents, $needle))
            ->toBeTrue(sprintf('operator-recovery.md must contain the substring "%s".', $needle));
    }

    // (b) Forbidden substrings — none of these may appear anywhere
    //     in the operator-facing runbook. The forbidden grammar
    //     captures both literal-string regressions and future
    //     decision-ID rolls (D-1101 through D-9999 all match
    //     /D-\d{4}/).
    expect(str_contains($contents, '.planning/'))
        ->toBeFalse('operator-recovery.md must not reference the GSD .planning/ tree.');

    expect(preg_match('/\bPhase \d{1,2}\b/', $contents))
        ->toBe(0, 'operator-recovery.md must not embed phase labels — docs describe current state.');

    expect(preg_match('/D-\d{4}/', $contents))
        ->toBe(0, 'operator-recovery.md must not embed four-digit decision IDs (D-####) — docs describe current state.');

    // (c) `cp database.sqlite` must appear exactly once AND that
    //     occurrence must be inside the "### DO NOT cp database.sqlite"
    //     warning subsection. The substr_count call catches stray
    //     duplicates; the heading assertion locks the warning's
    //     placement.
    expect(substr_count($contents, 'cp database.sqlite'))
        ->toBe(1, 'cp database.sqlite must appear exactly once in operator-recovery.md (inside the DO NOT subsection).');

    expect(str_contains($contents, '### DO NOT cp database.sqlite'))
        ->toBeTrue('operator-recovery.md must carry the "### DO NOT cp database.sqlite" warning subsection.');
});
