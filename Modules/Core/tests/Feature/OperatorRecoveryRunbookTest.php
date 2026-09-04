<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Support\PatternScan;

it('preserves the operator-facing contract of operator-recovery.md backups + recovery sections', function (): void {
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $runbookPath = base_path('.docs/runbooks/operator-recovery.md');

    expect($files->exists($runbookPath))->toBeTrue('.docs/runbooks/operator-recovery.md is expected at the canonical path.');

    $raw = $files->get($runbookPath);
    // Strip markdown comments so a commented-out decision id could not trip the
    // forbidden-substring assertions; `/s` makes `.` cross newlines.
    $contents = preg_replace('#<!--.*?-->#s', '', $raw) ?? $raw;

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

    expect(str_contains($contents, '.planning/'))
        ->toBeFalse('operator-recovery.md must not reference the GSD .planning/ tree.');

    expect(PatternScan::matches('/\bPhase \d{1,2}\b/', $contents))
        ->toBeFalse('operator-recovery.md must not embed phase labels — docs describe current state.');

    expect(PatternScan::matches('/D-\d{4}/', $contents))
        ->toBeFalse('operator-recovery.md must not embed four-digit decision IDs (D-####) — docs describe current state.');

    expect(substr_count($contents, 'cp database.sqlite'))
        ->toBe(1, 'cp database.sqlite must appear exactly once in operator-recovery.md (inside the DO NOT subsection).');

    expect(str_contains($contents, '### DO NOT cp database.sqlite'))
        ->toBeTrue('operator-recovery.md must carry the "### DO NOT cp database.sqlite" warning subsection.');
});
