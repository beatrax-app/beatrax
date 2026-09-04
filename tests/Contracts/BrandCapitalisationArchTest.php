<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * DES-R1 — the product name is written "Beatrax" in prose, mid-sentence included.
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
 * output, caches, generated NativePHP scaffolding, snapshot baselines, and the
 * agent tooling under .claude (whose sketch sources are frozen pre-rename
 * mockups).
 *
 * @return list<string>
 */
function brandSkippedDirectories(): array
{
    return [
        '.git', '.claude', '.phpstan-cache', '.phpunit.cache', '.playwright-mcp', 'vendor',
        'node_modules', 'build', 'cache', 'storage', 'snapshots', 'nativephp',
    ];
}

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

    foreach (brandScannedFiles() as $path) {
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
