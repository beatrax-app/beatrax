<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/00-index.md
 */

// The project's GitHub origin is written once, in ProjectLinks, and every
// other link is derived from it. Five hand-written copies had already drifted
// apart across Desktop, Shell, Core and Community before this guard landed,
// and the pair split over a Livewire component and its Blade template is the
// one a rename reliably misses.
const PROJECT_LINKS_CLASS = 'Modules/Core/Public/Support/ProjectLinks.php';

// Matches the https:// and the git@ spellings alike, so a clone URL pasted
// into app code cannot slip past the scheme-anchored form.
const PROJECT_LINKS_ORIGIN_PATTERN = '#github\.com[/:]beatrax-app/beatrax#';

// The org half is what drifts, and it drifts silently: a link can name
// github.com and the right repository while pointing at an org that does not
// host it. Twenty-six locale files shipped `beatrax/beatrax`, so the
// Translations button 404'd in every language while reading as correct.
const PROJECT_LINKS_ORG = 'beatrax-app';

const PROJECT_LINKS_ANY_ORG_PATTERN = '#github\.com[/:]([A-Za-z0-9._-]+)/beatrax\b#';

/**
 * @return list<string> repo-relative paths, tests and build output excluded
 */
function projectLinksScannedFiles(): array
{
    // mobile-app/ is a second Composer root whose Modules/ is a symlink onto
    // this tree, so resolving it gives the one root both roots agree on.
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    $extensions = ['php', 'js', 'mjs', 'json', 'yml', 'yaml'];
    $files = [];

    foreach (['Modules', 'app', 'config', 'routes', 'database', 'bootstrap', 'resources', 'scripts'] as $directory) {
        $root = $repoRoot.'/'.$directory;
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }

            $relative = str_replace($repoRoot.'/', '', $path);
            // A test naming the URL is asserting about it rather than shipping
            // it, and compiled Blade under storage/ and the bootstrap config
            // cache are build output that regenerate from what is scanned here.
            if (preg_match('#(?:^|/)(?:tests|vendor|node_modules|storage|build|cache)/#', $relative) === 1) {
                continue;
            }

            $files[] = $relative;
        }
    }
    sort($files);

    return $files;
}

it('writes the GitHub origin once, in ProjectLinks', function (): void {
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    $offenders = [];
    foreach (projectLinksScannedFiles() as $relative) {
        if ($relative === PROJECT_LINKS_CLASS) {
            continue;
        }

        $source = (string) file_get_contents($repoRoot.'/'.$relative);
        if (preg_match_all(PROJECT_LINKS_ORIGIN_PATTERN, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[0] as [, $offset]) {
            $offenders[] = $relative.':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'The GitHub origin belongs to Modules\Core\Public\Support\ProjectLinks and',
        'nowhere else — renaming the org or the repository has to be one edit. Derive',
        'the link from a constant there, or add one; a Blade template reaches it with',
        "@use('Modules\Core\Public\Support\ProjectLinks'), never a global helper.",
        'These spell the origin out again:',
        ...$offenders,
    ]));
});

it('keeps every site that had its own copy reading it from ProjectLinks', function (): void {
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    $consumers = [
        'Modules/Desktop/Internal/Native/AppMenuBuilder.php',
        'Modules/Shell/Internal/Http/Livewire/SettingsPage.php',
        'Modules/Core/Resources/views/livewire/partials/system-alert-actions.blade.php',
        'Modules/Community/Internal/Services/GitHubCompareUrlBuilder.php',
        'config/community.php',
    ];

    $stale = [];
    foreach ($consumers as $relative) {
        $path = $repoRoot.'/'.$relative;
        if (! is_file($path)) {
            $stale[] = $relative.'  (file is gone)';

            continue;
        }
        if (! str_contains((string) file_get_contents($path), 'ProjectLinks')) {
            $stale[] = $relative.'  (no longer names ProjectLinks)';
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'These five sites each held their own copy of the GitHub origin. A site that',
        'stops naming ProjectLinks has either grown a copy back or dropped the link —',
        'if the link is genuinely gone, delete the line here too:',
        ...$stale,
    ]));
});

it('finds the file it scans from either Composer root', function (): void {
    expect(projectLinksScannedFiles())
        ->toContain(PROJECT_LINKS_CLASS)
        ->toContain('config/community.php');
});

it('never points a beatrax link at an org that does not host it', function (): void {
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    $offenders = [];
    foreach (projectLinksScannedFiles() as $relative) {
        $source = (string) file_get_contents($repoRoot.'/'.$relative);
        if (preg_match_all(PROJECT_LINKS_ANY_ORG_PATTERN, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[1] as $index => [$org]) {
            if ($org === PROJECT_LINKS_ORG) {
                continue;
            }

            [, $offset] = $matches[0][$index];
            $offenders[] = $relative.':'.(substr_count(substr($source, 0, $offset), "\n") + 1).'  → '.$org.'/beatrax';
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A GitHub link naming this repository under another org resolves to a 404, and',
        'it reads as correct at a glance. The only org that hosts Beatrax is',
        PROJECT_LINKS_ORG.'. These name a different one:',
        ...$offenders,
    ]));
});
