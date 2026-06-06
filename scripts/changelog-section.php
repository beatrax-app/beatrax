#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Extracts a single version's section from CHANGELOG.md and prints it to
 * stdout. Used by the release workflow to populate the GitHub Release body
 * from the changelog, keeping CHANGELOG.md the single source of truth.
 *
 * Usage:
 *   php scripts/changelog-section.php <version> [path/to/CHANGELOG.md]
 *
 * <version> may be given with or without a leading "v" (e.g. "v1.2.0" or
 * "1.2.0"); it is matched against the "## [1.2.0] - ..." heading.
 *
 * Exit codes:
 *   0  section found and printed
 *   1  usage error / changelog unreadable
 *   2  no section for the requested version (the caller can decide whether
 *      that is fatal — a tag with no changelog entry usually is).
 */
$argvVersion = $argv[1] ?? null;
if ($argvVersion === null || $argvVersion === '') {
    fwrite(STDERR, "usage: changelog-section.php <version> [changelog-path]\n");
    exit(1);
}

$version = ltrim($argvVersion, 'vV');
$path = $argv[2] ?? dirname(__DIR__).'/CHANGELOG.md';

$contents = @file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "could not read changelog at {$path}\n");
    exit(1);
}

$lines = preg_split('/\R/', $contents) ?: [];

// A version heading looks like "## [1.2.0] - 2026-06-30" (the date is
// optional). The link-reference footer "[1.2.0]: https://..." is intentionally
// NOT matched because it starts with a single "[", not "## [".
$headingPattern = '/^##\s+\[(.+?)\]/';

$section = [];
$capturing = false;

foreach ($lines as $line) {
    if (preg_match($headingPattern, $line, $matches) === 1) {
        if ($capturing) {
            break; // reached the next version heading — stop.
        }
        if (strcasecmp(trim($matches[1]), $version) === 0) {
            $capturing = true;

            continue; // skip the heading line itself; the body is the notes.
        }

        continue;
    }

    if ($capturing) {
        $section[] = $line;
    }
}

if (! $capturing) {
    fwrite(STDERR, "no changelog section found for version {$version}\n");
    exit(2);
}

// Trim leading/trailing blank lines so the release body is tidy.
while ($section !== [] && trim($section[0]) === '') {
    array_shift($section);
}
while ($section !== [] && trim($section[array_key_last($section)]) === '') {
    array_pop($section);
}

echo implode("\n", $section)."\n";
exit(0);
