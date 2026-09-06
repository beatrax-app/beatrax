<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

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

// Every root holding first-party source of the kinds below. lang/ is here
// because the twenty-six locale files that shipped the wrong org are lang
// files, and a rule written after that failure walked past them.
const PROJECT_LINKS_ROOTS = ['.claude', 'Modules', 'app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'scripts', 'tools'];

const PROJECT_LINKS_EXTENSIONS = ['php', 'js', 'mjs', 'json', 'yml', 'yaml'];

/**
 * @return list<string> repo-relative paths, tests and build output excluded
 */
function projectLinksScannedFiles(): array
{
    // mobile-app/ is a second Composer root whose Modules/ is a symlink onto
    // this tree, so resolving it gives the one root both roots agree on.
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    $files = [];

    foreach (PROJECT_LINKS_ROOTS as $directory) {
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
            if (! $file->isFile() || $file->isLink() || ! in_array(strtolower($file->getExtension()), PROJECT_LINKS_EXTENSIONS, true)) {
                continue;
            }

            $relative = str_replace($repoRoot.'/', '', $path);
            // A test naming the URL is asserting about it rather than shipping
            // it. The other four are generated: node_modules and vendor are
            // somebody else's source, public/build is the Vite bundle every
            // packaged shell carries, and bootstrap/cache holds the compiled
            // config — each regenerates from a file this walk already reads,
            // and each is absent from a clean checkout and present in a built
            // one, which is why they are skipped rather than deleted for
            // excusing nothing here today.
            if (preg_match('#(?:^|/)(?:tests|vendor|node_modules|build|cache)/#', $relative) === 1) {
                continue;
            }

            $files[] = $relative;
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

/** @return list<int> the 1-based lines on which $source spells the origin out */
function projectLinksOriginLinesIn(string $source): array
{
    $lines = [];

    foreach (PatternScan::allWithOffsets(PROJECT_LINKS_ORIGIN_PATTERN, $source)[0] as [, $offset]) {
        $lines[] = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
    }

    return $lines;
}

/** @return list<array{org: string, line: int}> the Beatrax links $source points at another org */
function projectLinksForeignOrgsIn(string $source): array
{
    $matches = PatternScan::allWithOffsets(PROJECT_LINKS_ANY_ORG_PATTERN, $source);
    $found = [];

    foreach ($matches[1] as $index => [$org]) {
        if ($org === PROJECT_LINKS_ORG) {
            continue;
        }

        [, $offset] = $matches[0][$index];
        $found[] = ['org' => $org, 'line' => substr_count(substr($source, 0, (int) $offset), "\n") + 1];
    }

    return $found;
}

it('writes the GitHub origin once, in ProjectLinks', function (): void {
    $repoRoot = dirname((string) realpath(base_path('Modules')));
    $files = projectLinksScannedFiles();

    // Read before the verdict: a walk that resolved nothing reports the same
    // single home a tree with one copy does. The floor sits far under today's
    // 7,144.
    expect(count($files))->toBeGreaterThan(
        3000,
        'the walk resolved '.count($files).' files, which is too few to be this repository.'
    );

    $offenders = [];
    foreach ($files as $relative) {
        if ($relative === PROJECT_LINKS_CLASS) {
            continue;
        }

        $source = (string) file_get_contents($repoRoot.'/'.$relative);

        foreach (projectLinksOriginLinesIn($source) as $line) {
            $offenders[] = $relative.':'.$line;
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
        'Modules/Core/Public/Http/Livewire/UpdateCheckSettingsSection.php',
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

// ProjectLinks is exempted from the rule above by path, which is the widest
// exemption shape there is. It earns that by being where the origin is
// written, and this is what re-checks the earning: when the constant moves,
// the exemption is excusing a file that no longer holds the thing.
it('finds the file it scans from either Composer root, and finds the origin in it', function (): void {
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    expect(projectLinksScannedFiles())
        ->toContain(PROJECT_LINKS_CLASS)
        ->toContain('config/community.php');

    expect(projectLinksOriginLinesIn((string) file_get_contents($repoRoot.'/'.PROJECT_LINKS_CLASS)))->not->toBe(
        [],
        PROJECT_LINKS_CLASS.' no longer spells the origin out, so the one file exempted from the rule '
        .'above is exempted for something it no longer does — and the real home of the constant is unguarded.'
    );
});

it('never points a Beatrax link at an org that does not host it', function (): void {
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    $offenders = [];
    foreach (projectLinksScannedFiles() as $relative) {
        foreach (projectLinksForeignOrgsIn((string) file_get_contents($repoRoot.'/'.$relative)) as $found) {
            $offenders[] = $relative.':'.$found['line'].'  → '.$found['org'].'/beatrax';
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A GitHub link naming this repository under another org resolves to a 404, and',
        'it reads as correct at a glance. The only org that hosts Beatrax is',
        PROJECT_LINKS_ORG.'. These name a different one:',
        ...$offenders,
    ]));
});

// Both rules report on a tree that has been cleaned of what they look for, so
// the readers are driven against planted sources. The near-misses are the two
// shapes that must stay legible: the org that does host it, and a link to
// somewhere else on github.com entirely.
it('sees a second copy of the origin and a link to an org that does not host it', function (): void {
    $origin = 'github.com'.'/beatrax-app/beatrax';

    expect(projectLinksOriginLinesIn("<?php\n\$url = 'https://".$origin."/releases';"))->toBe([2])
        ->and(projectLinksOriginLinesIn('git@'.'github.com'.':beatrax-app/beatrax.git'))->toBe([1])
        ->and(projectLinksOriginLinesIn('<?php $url = ProjectLinks::REPO_URL;'))->toBe([]);

    // Assembled rather than written out, so no whole URL to a repository that
    // does not exist sits in this file for a link checker to resolve.
    $host = 'https://'.'github.com';

    expect(projectLinksForeignOrgsIn($host.'/nightworks/beatrax-community/compare'))
        ->toBe([['org' => 'nightworks', 'line' => 1]])
        ->and(projectLinksForeignOrgsIn("\n\n".$host.'/beatrax/beatrax/issues'))
        ->toBe([['org' => 'beatrax', 'line' => 3]])
        ->and(projectLinksForeignOrgsIn('https://'.$origin.'/issues'))->toBe([])
        ->and(projectLinksForeignOrgsIn($host.'/laravel/framework'))->toBe([]);
});
