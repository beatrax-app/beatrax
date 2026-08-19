<?php

declare(strict_types=1);

/*
 * This app renders dates day-first. A census of the tree found 32 call sites
 * already using `d M Y`, `d M`, `j M Y` or `d M Y · H:i`, and exactly four
 * month-first ones — all on the calendar, all reaching a Dutch UI:
 *
 *   "Saldo daalt onder € 0 op 18 dagen — eerste: aug. 1."
 *   aria-label "augustus 18, 2026: 0 betalingen"
 *
 * The month name translated and the order did not, which reads as a bug in
 * the translation rather than in the format string.
 */

it('never formats a date month-first', function (): void {
    $offenders = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! preg_match('/\.(php|blade\.php)$/', $path)) {
                continue;
            }

            if (str_contains($path, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            // M/F is the month, j/d the day. A pattern that opens with the
            // month and then names the day is US order.
            preg_match_all("/translatedFormat\('([^']+)'\)/", $source, $matches);

            foreach ($matches[1] as $pattern) {
                if (preg_match('/^[MF][^jd]*[jd]/', $pattern) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $path)." — '{$pattern}'";
                }
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These render the month before the day:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
