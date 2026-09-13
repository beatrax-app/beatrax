<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * The product name is written "Beatrax" in prose, mid-sentence included.
 *
 * @link ../../.docs/conventions/00-index.md
 */

/**
 * Every identifier the brand keeps lowercase, spelled out as a lookaround so
 * the guard cannot fire on one: anything glued to a word character, hyphen,
 * slash, at-sign or namespace separator (`beatrax-app/beatrax`, `beatrax_id`,
 * `/opt/beatrax`, `Beatrax\BiometricVault`), a dotted segment on either side
 * (`com.beatrax.mobile`, `beatrax.sqlite`), and an artisan signature or URI
 * scheme (`beatrax:install`, `beatrax:*`, `beatrax://pair`).
 */
function brandProsePattern(): string
{
    return '#(?<![A-Za-z0-9_\-/@\\\\])(?<![A-Za-z0-9]\.)beatrax(?![A-Za-z0-9_\-/@\\\\])(?!\.[A-Za-z0-9_])(?!:[A-Za-z0-9_*/])#';
}

/**
 * Directory names that never hold hand-written prose: third-party code, build
 * output, caches, generated NativePHP scaffolding and snapshot baselines.
 *
 * @return list<string>
 */
function brandSkippedDirectories(): array
{
    return [
        '.git', '.phpstan-cache', '.phpunit.cache', '.playwright-mcp', 'vendor',
        'node_modules', 'build', 'cache', 'storage', 'snapshots', 'nativephp',
    ];
}

// The whole of .claude used to be skipped for a reason that is true of one
// directory inside it: the sketch sources are frozen HTML mockups captured
// before the rename, and re-flipping them would rewrite a record of what was
// shown rather than a page anybody reads. Everything else the agent tooling
// carries is prose a contributor reads, so it is in scope.
const BRAND_FROZEN_MOCKUPS = '.claude/skills/sketch-findings-beatrax/sources/';

/**
 * Every prose-bearing file in the repository. .env templates, .properties,
 * .toml, .sh and .plist are out of scope on purpose: their brand uses are
 * config values and 1Password item titles ("beatrax iOS signing config"),
 * which no matcher can tell apart from a sentence.
 *
 * @return list<string> absolute paths
 */
function brandScannedFiles(): array
{
    $extensions = ['.md', '.php', '.json', '.webmanifest', '.html', '.js', '.swift', '.kt', '.yml', '.yaml'];
    // Two generated dependency manifests, and this file, which has to spell the
    // lowercase form out in its own pattern and in its own dataset.
    $skippedNames = ['composer.lock', 'package-lock.json', basename(__FILE__)];
    $skipped = brandSkippedDirectories();

    $directories = new RecursiveDirectoryIterator(base_path(), RecursiveDirectoryIterator::SKIP_DOTS);
    $pruned = new RecursiveCallbackFilterIterator(
        $directories,
        static fn (SplFileInfo $file): bool => ! $file->isDir() || ! in_array($file->getFilename(), $skipped, true),
    );

    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator($pruned) as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || in_array($file->getFilename(), $skippedNames, true)) {
            continue;
        }
        if (str_starts_with(str_replace(base_path().'/', '', $path), BRAND_FROZEN_MOCKUPS)) {
            continue;
        }
        foreach ($extensions as $extension) {
            if (str_ends_with($path, $extension)) {
                $files[] = $path;
                break;
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * The lines of $contents that use the brand as prose, 1-based.
 *
 * @return list<int>
 */
function brandProseLines(string $relative, string $contents): array
{
    $isMarkdown = str_ends_with($relative, '.md');
    $isPhp = str_ends_with($relative, '.php');
    $isYaml = str_ends_with($relative, '.yml') || str_ends_with($relative, '.yaml');

    $lines = [];
    $inFence = false;

    foreach (explode("\n", $contents) as $index => $line) {
        $text = $line;

        if ($isMarkdown) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $text) === 1) {
                $inFence = ! $inFence;

                continue;
            }
            if ($inFence) {
                continue;
            }
            $text = PatternScan::replace('/\]\([^)]*\)/', ']()', $text);
            $text = PatternScan::replace('#https?://\S+#', '', $text);
        }

        // A backticked span is the name being typed, not written about.
        if ($isMarkdown || $isPhp) {
            $text = PatternScan::replace('/`[^`]*`/', '``', $text);
        }

        // A quoted string that is nothing but the token is a value — the
        // database name, the composer vendor, the URI scheme. Prose is never
        // the bare word alone.
        if ($isPhp || $isYaml) {
            $text = PatternScan::replace('/([\'"])beatrax\1/', '$1$1', $text);
        }
        if ($isYaml) {
            $text = PatternScan::replace('/:\s*beatrax\s*$/', ': ', $text);
        }

        if (preg_match(brandProsePattern(), $text) === 1) {
            $lines[] = $index + 1;
        }
    }

    return $lines;
}

it('writes the product name as "Beatrax" wherever it appears in prose', function (): void {
    $offenders = [];
    $files = brandScannedFiles();

    // Ten thousand prose-bearing files stand behind the empty list below.
    expect(count($files))->toBeGreaterThan(
        3_000,
        'The walk read almost nothing, so the empty offender list below is a tree nobody opened.',
    );

    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (brandProseLines($relative, (string) file_get_contents($path)) as $line) {
            $offenders[] = $relative.':'.$line;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['DES-R1: the product name is written "Beatrax" in prose, mid-sentence included.',
            'Identifiers stay lowercase — `beatrax:install`, `com.beatrax.mobile`, `/opt/beatrax`,',
            '`beatrax.sqlite`, `beatrax-app/beatrax`, `beatrax://pair` and fenced code all pass.',
            'Offenders:'],
        $offenders,
    )));
});

it('passes every identifier form and fails the prose forms', function (): void {
    $allowed = <<<'TEXT'
        php artisan beatrax:install
        beatrax:*
        com.beatrax.mobile
        /opt/beatrax
        beatrax.sqlite
        beatrax-app/beatrax
        beatrax://pair?v=1
        beatrax_id
        Beatrax\BiometricVault
        user@beatrax.local
        Beatrax is a local-first dashboard.
        TEXT;

    $rejected = <<<'TEXT'
        beatrax is a local-first dashboard.
        Open beatrax and choose Settings.
        the tray's "Open beatrax" item
        You probably do not need beatrax.
        beatrax's own base currency
        TEXT;

    expect(brandProseLines('sample.txt', $allowed))->toBe([])
        ->and(brandProseLines('sample.txt', $rejected))->toBe([1, 2, 3, 4, 5]);
});

// The one path-shaped exemption in this file, held to the reason it was granted
// for: a frozen mockup that no longer spells the old brand needs no exemption,
// and the prefix then covers whatever is written under it next.
it('keeps the frozen-mockup exemption only while the mockups are still frozen', function (): void {
    $frozen = glob(base_path(BRAND_FROZEN_MOCKUPS).'*/index.html') ?: [];

    expect(count($frozen))->toBeGreaterThan(
        1,
        BRAND_FROZEN_MOCKUPS.' holds almost no captured mockup, so the exemption covers nothing.',
    );

    $stillLowercase = 0;

    foreach ($frozen as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $stillLowercase += brandProseLines($relative, (string) file_get_contents($path)) === [] ? 0 : 1;
    }

    expect($stillLowercase)->toBeGreaterThan(
        0,
        'Every captured mockup now writes the brand the way the rule asks, so the exemption excuses '
        .'nothing while standing ready to excuse the next capture. Delete BRAND_FROZEN_MOCKUPS and the '
        .'skip beside it.',
    );
});
