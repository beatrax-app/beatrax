<?php

declare(strict_types=1);

it('flattens a parsed date to its day through SafeDate and nowhere else', function (): void {
    $offenders = [];

    foreach ([base_path('Modules'), base_path('app')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_ends_with($path, 'Public/Support/SafeDate.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (preg_match_all('~parseOrNull\([^;]*\)\?->startOfDay\(\)~', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as [$hit, $offset]) {
                $offenders[] = sprintf(
                    '%s:%d  %s',
                    str_replace(base_path().'/', '', $path),
                    substr_count(substr($source, 0, $offset), "\n") + 1,
                    trim($hit),
                );
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A date-only field flattened to its day is SafeDate::parseDayOrNull().',
        'These spell it out a second time instead:',
        ...$offenders,
    ]));
});

it('spells the snooze windows once, in the enum that owns them', function (): void {
    $offenders = [];

    foreach ([base_path('Modules'), base_path('app'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! preg_match('~\.(?:php|blade\.php)$~', $path)) {
                continue;
            }
            if (str_ends_with($path, 'Public/Enums/SnoozeWindow.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            preg_match_all('~\{\{--.*?--\}\}|/\*\*.*?\*/|/\*.*?\*/|//[^\n]*~s', $source, $comments, PREG_OFFSET_CAPTURE);

            foreach ($comments[0] as [$comment, $offset]) {
                if (str_contains($comment, '1w') && str_contains($comment, '3m')) {
                    $offenders[] = sprintf(
                        '%s:%d',
                        str_replace(base_path().'/', '', $path),
                        substr_count(substr($source, 0, $offset), "\n") + 1,
                    );
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'SnoozeWindow holds the three windows and their wire values. A comment',
        'that lists them again is a copy that goes stale the moment a fourth is',
        'added, and one already had:',
        ...$offenders,
    ]));
});
