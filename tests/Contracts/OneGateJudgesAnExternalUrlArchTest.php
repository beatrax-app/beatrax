<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/an-external-url-is-judged-once.md
 */

// A URL the community corpus supplies is attacker-influenceable, and this is a
// desktop shell rather than a browser tab: `target="_blank"` opens another
// window of this application, and a notification deep link replaces the address
// of the main one. Every judgement about such a URL belongs to one gate.

/** @return list<string> absolute paths to every Blade template this repository owns */
function externalUrlBladeFiles(): array
{
    $roots = [base_path('Modules'), base_path('resources')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/** @return list<string> absolute paths to every shipped PHP source, tests excluded */
function externalUrlPhpSources(): array
{
    $files = [];
    // Every root that ships PHP, not Modules and app alone: "one place only" is
    // a claim about the application, and a route file or a release script
    // opening a URL would have been invisible to the narrower pair.
    foreach (['Modules', 'app', 'bootstrap', 'config', 'database', 'routes', 'scripts'] as $name) {
        $root = base_path($name);

        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// A template that decides a scheme for itself is how `http://` shipped: the
// corpus reader upstream refused everything but https, and the two templates
// rendering what it admitted each carried a laxer copy of the rule.
const EXTERNAL_URL_SCHEME_TEST = '/(?:str_starts_with|str_contains|strpos|stripos|preg_match)\s*\([^)]*[\'"](?:https?|javascript|data|file|vbscript|blob):/i';

// Line by line, so the report names the line a contributor has to edit rather
// than the file it is somewhere inside.
/**
 * @return list<string> "$label:$line" for every scheme test the source makes
 */
function externalUrlSchemeOffendersIn(string $source, string $label): array
{
    $offenders = [];

    foreach (explode("\n", $source) as $index => $line) {
        if (PatternScan::matches(EXTERNAL_URL_SCHEME_TEST, $line)) {
            $offenders[] = $label.':'.($index + 1);
        }
    }

    return $offenders;
}

it('leaves no template judging a URL scheme for itself', function (): void {
    $files = externalUrlBladeFiles();
    expect(count($files))->toBeGreaterThan(150, 'The Blade walk found almost nothing, so a clean answer below is the walk being broken rather than the templates being right.');

    $offenders = [];
    foreach ($files as $path) {
        foreach (externalUrlSchemeOffendersIn((string) file_get_contents($path), $path) as $offender) {
            $offenders[] = $offender;
        }
    }

    expect($offenders)->toBe(
        [],
        'A Blade template must not test a URL scheme. Whatever supplies the value judges it once, through '
        ."Modules\\Core\\Public\\Support\\ExternalUrl, before it ever reaches a template.\n  "
        .implode("\n  ", $offenders),
    );
});

// The sites allowed past the two call-site rules below, each with the rule it
// is spared from, the reason it was spared, and the pattern a companion case
// re-runs against it — so a pin whose site has moved fails as loudly as a new
// caller does.
/**
 * @var array<string, array{rule: string, reason: string, proves: string}>
 */
const EXTERNAL_URL_PINNED_CALLERS = [
    'Modules/Desktop/Internal/Native/DesktopUrlOpener.php' => [
        'rule' => 'openExternal',
        'reason' => 'the desktop half of ExternalUrlOpener, and the only place the desktop shell is named for a URL; the gate above it has already judged the address',
        'proves' => '/implements ExternalUrlOpener/',
    ],
    'Modules/Desktop/Internal/Listeners/NavigateOnNotificationDeepLink.php' => [
        'rule' => 'windowUrl',
        'reason' => 'the one place a deep link is checked to be this application before it replaces the address of its own window',
        'proves' => '/Window::get\(/',
    ],
];

// Read off the pins rather than restated beside them: a second copy of the list
// is a second thing to keep in step, and the companion case re-proves only the
// copy it can see.
/**
 * @return list<string>
 */
function externalUrlPinnedFor(string $rule): array
{
    return array_keys(array_filter(
        EXTERNAL_URL_PINNED_CALLERS,
        static fn (array $pin): bool => $pin['rule'] === $rule,
    ));
}

// `rel` is the other half of the same sentence. Without `noopener` the opened
// window holds `window.opener` on the one that opened it, and in this shell that
// is another window of this application; without `noreferrer` the third party is
// told which screen the reader was on when they left.
it('gives every new-window link both halves of rel', function (): void {
    $offenders = [];
    $anchors = 0;

    foreach (externalUrlBladeFiles() as $file) {
        $source = (string) file_get_contents($file);

        // Through MarkupSource, never a pattern shaped like a tag: `target` and
        // `rel` are written on separate lines here in either order, and an
        // x-data or @class([...]) between them carries characters a tag pattern
        // reads as the end of the tag.
        foreach (MarkupSource::tags($source) as $element) {
            if ($element->attribute('target') !== '_blank') {
                continue;
            }

            $anchors++;
            $rel = $element->attribute('rel') ?? '';

            if (! str_contains($rel, 'noopener') || ! str_contains($rel, 'noreferrer')) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }
    }

    // Read before the verdict: no anchors found is a scan that stopped, and it
    // reads exactly like a tree with nothing to fix.
    expect($anchors)->toBeGreaterThan(
        4,
        'The walk found '.$anchors.' new-window links, too few to be this application.'
    );

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n", [
        'A target="_blank" link needs rel="noopener noreferrer".',
        'This is a desktop shell: the window it opens is another window of this',
        'application, same preload, sandbox: false. Offenders:',
        implode(', ', array_unique($offenders)),
    ]));
});

it('hands a URL to the operating system from one place only', function (): void {
    $sanctioned = externalUrlPinnedFor('openExternal');

    $sources = externalUrlPhpSources();
    expect(count($sources))->toBeGreaterThan(2000, 'The PHP walk found almost nothing, so a clean answer below is the walk being broken rather than the tree being right.');

    $callers = [];
    foreach ($sources as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if (in_array($relative, $sanctioned, true)) {
            continue;
        }
        if (PatternScan::matches('/->openExternal\s*\(/', (string) file_get_contents($path))) {
            $callers[] = $relative;
        }
    }

    expect($callers)->toBe(
        [],
        'openExternal() is reached through OpenExternalUrlAction, which applies the https check and the host '
        ."allow-list. A second caller is a second policy.\n  ".implode("\n  ", $callers),
    );
});

it('sets the address of the application window from one place only', function (): void {
    $sanctioned = externalUrlPinnedFor('windowUrl');

    $sources = externalUrlPhpSources();
    expect(count($sources))->toBeGreaterThan(2000, 'The PHP walk found almost nothing, so a clean answer below is the walk being broken rather than the tree being right.');

    $callers = [];
    foreach ($sources as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if (in_array($relative, $sanctioned, true)) {
            continue;
        }
        if (PatternScan::matches('/Window::get\s*\([^)]*\)\s*->url\s*\(/', (string) file_get_contents($path))) {
            $callers[] = $relative;
        }
    }

    expect($callers)->toBe(
        [],
        "Window::get(...)->url() replaces the address of the application's own window, so its argument has to be "
        ."this application. NavigateOnNotificationDeepLink is where that is checked.\n  ".implode("\n  ", $callers),
    );
});

it('lets the gate judge every URL the corpus supplies', function (): void {
    $admissionPoints = [
        'Modules/Community/Public/Services/SupportResourceProvider.php',
        'Modules/Community/Internal/Corpus/MerchantContactReader.php',
    ];

    $ungated = [];
    foreach ($admissionPoints as $relative) {
        $source = (string) file_get_contents(base_path($relative));
        if (! PatternScan::matches('/ExternalUrl::refusalFor\s*\(/', $source)) {
            $ungated[] = $relative;
        }
    }

    expect($ungated)->toBe(
        [],
        'Every corpus URL enters the application through one of these readers, and each one asks the gate. A '
        ."reader that stops asking is a reader that admits whatever the corpus says.\n  ".implode("\n  ", $ungated),
    );
});

it('still holds each pinned caller to the reason it was granted for', function (): void {
    $stale = [];

    foreach (EXTERNAL_URL_PINNED_CALLERS as $relative => $pin) {
        $path = base_path($relative);

        if (! is_file($path)) {
            $stale[] = $relative.'  (file is gone) — '.$pin['reason'];

            continue;
        }

        if (! PatternScan::matches($pin['proves'], (string) file_get_contents($path))) {
            $stale[] = $relative.'  (no longer reads as: '.$pin['reason'].')';
        }
    }

    expect($stale)->toBe(
        [],
        'A site excused from the call-site rules above has stopped being what earned it the exemption. Move '
        .'the pin to wherever the responsibility went, or delete it and let the scan cover the file again. '
        ."Offenders:\n  ".implode("\n  ", $stale),
    );
});

it('reads a scheme test written in a template and leaves a rendered URL alone', function (): void {
    $judging = <<<'BLADE'
        <div>
        @if (str_starts_with($url, 'https://'))
            <a href="{{ $url }}">{{ $url }}</a>
        @endif
        </div>
        BLADE;

    // The near miss: naming the scheme is not judging it, and a template that
    // only prints a URL the gate already admitted must stay green.
    $rendering = <<<'BLADE'
        <a href="{{ $url }}">https://example.test</a>
        BLADE;

    expect(externalUrlSchemeOffendersIn($judging, 'v'))->toBe(['v:2'])
        ->and(externalUrlSchemeOffendersIn($rendering, 'v'))->toBe([]);
});
