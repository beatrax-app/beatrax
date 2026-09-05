<?php

declare(strict_types=1);

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
    foreach ([base_path('Modules'), base_path('app')] as $root) {
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

it('leaves no template judging a URL scheme for itself', function (): void {
    $files = externalUrlBladeFiles();
    expect($files)->not->toBe([]);

    $offenders = [];
    foreach ($files as $path) {
        foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
            if (PatternScan::matches(EXTERNAL_URL_SCHEME_TEST, $line)) {
                $offenders[] = $path.':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'A Blade template must not test a URL scheme. Whatever supplies the value judges it once, through '
        ."Modules\\Core\\Public\\Support\\ExternalUrl, before it ever reaches a template.\n  "
        .implode("\n  ", $offenders),
    );
});

it('hands a URL to the operating system from one place only', function (): void {
    $sanctioned = [
        'Modules/Community/Public/Actions/OpenExternalUrlAction.php',
        'Modules/Community/Internal/Shell/NoOpShell.php',
    ];

    $sources = externalUrlPhpSources();
    expect($sources)->not->toBe([]);

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
    $sanctioned = 'Modules/Desktop/Internal/Listeners/NavigateOnNotificationDeepLink.php';

    $callers = [];
    foreach (externalUrlPhpSources() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if ($relative === $sanctioned) {
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
