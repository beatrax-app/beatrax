<?php

declare(strict_types=1);

// An `i18n-review:` marker is a question left for a native reader: the line was
// written by somebody who could reason about the grammar but could not settle
// the register, and it names the locale and the key it is asking about. There
// are enough of them that nobody re-reads them all, which is exactly how a
// marker rots — the key gets renamed or the line gets deleted, and the marker
// stays behind pointing at nothing while still reading like open work.
//
// This does not review anything. It keeps every marker answerable: the locale
// it claims is the locale it sits in, and the key it names is still in the file
// under it.

/** @return list<array{file: string, line: int, locale: string, keys: string}> */
function i18nReviewMarkers(): array
{
    $markers = [];
    $root = base_path('Modules');

    if (! is_dir($root)) {
        return $markers;
    }

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php') || ! str_contains($path, '/Resources/lang/')) {
            continue;
        }

        $lines = file($path);

        if ($lines === false) {
            throw new RuntimeException($path.' could not be read');
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/i18n-review:\s*([a-z]{2})\s*·\s*([^—]+)—/u', $line, $match) !== 1) {
                continue;
            }

            $markers[] = [
                'file' => $path,
                'line' => $index + 1,
                'locale' => $match[1],
                'keys' => trim($match[2]),
            ];
        }
    }

    return $markers;
}

/**
 * Every key in the file, both as a full dotted path and as its own leaf, because
 * a marker sits directly above the line it asks about and names it the way the
 * file spells it there — `amount_unreadable`, not `errors.amount_unreadable`.
 *
 * @param  array<string, mixed>  $lines
 * @return list<string>
 */
function i18nKnownKeys(array $lines, string $prefix = ''): array
{
    $known = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $known[] = $path;
        $known[] = (string) $key;

        if (is_array($value)) {
            $known = array_merge($known, i18nKnownKeys($value, $path));
        }
    }

    return $known;
}

it('leaves every review marker naming the locale it sits in and a key that is still there', function (): void {
    $markers = i18nReviewMarkers();

    // A walk that found nothing would pass while saying nothing. These exist in
    // quantity; the floor asserts the scan read a tree rather than an empty one.
    expect(count($markers))->toBeGreaterThan(50);

    $offenders = [];

    foreach ($markers as $marker) {
        $relative = str_replace(base_path().'/', '', $marker['file']);
        $at = $relative.':'.$marker['line'];

        if (preg_match('#/lang/([a-z]+)/#', $marker['file'], $dir) === 1 && $dir[1] !== $marker['locale']) {
            $offenders[] = $at.' — marked "'.$marker['locale'].'" but sits in '.$dir[1];
        }

        /** @var array<string, mixed> $lines */
        $lines = include $marker['file'];
        $known = i18nKnownKeys($lines);

        foreach (preg_split('/\s*,\s*/', $marker['keys']) ?: [] as $key) {
            // A trailing `.*` marks a group the question covers as a whole.
            $key = rtrim(trim($key), '.*');

            if ($key === '') {
                continue;
            }

            if (! in_array($key, $known, true)) {
                $offenders[] = $at.' — asks about "'.$key.'", which this file no longer has';
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A review marker is a question with an address. These have lost theirs —',
        'answer and delete the marker, or repoint it at the line it now means:',
        ...$offenders,
    ]));
});
