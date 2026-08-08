<?php

declare(strict_types=1);

/*
 * Every plain POST form in a Blade view must submit through
 * `beatraxSubmitPostForm`.
 *
 * A native form POST does not survive the mobile shell. NativePHP intercepts
 * WebView requests and replays them into the embedded runtime; its form path
 * builds the body with `new FormData(form)` — which omits the submitter
 * button's own name/value — and the replayed request loses the POST method, so
 * Laravel answers 405 with `Allow: POST`. The app then follows the redirect
 * back to the same URL and loops on the error page.
 *
 * It fails silently: no exception, no console error, just a control that does
 * nothing. Sign-out and the pre-auth language switch were both dead on device
 * before this guard existed, and nothing in the suite noticed.
 */

/** @return list<string> */
function bladeViewFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources/views'] as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $it */
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('routes every plain POST form through the fetch submitter', function (): void {
    $offenders = [];

    foreach (bladeViewFiles() as $path) {
        $contents = (string) file_get_contents($path);

        // Livewire owns its own submits via wire:submit and never issues a
        // native form POST, so only forms declaring method="POST" are in scope.
        if (! preg_match_all('/<form\b[^>]*method="POST"[^>]*>/i', $contents, $matches)) {
            continue;
        }

        foreach ($matches[0] as $tag) {
            if (! str_contains($tag, 'beatraxSubmitPostForm')) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        "A native form POST is silently dropped by the mobile shell. Add\n".
        '  x-data x-on:submit.prevent="beatraxSubmitPostForm($el, $event.submitter)"'."\n".
        "to the <form> tag in:\n  ".implode("\n  ", array_unique($offenders)),
    );
});

it('keeps the submitter helper available to those views', function (): void {
    // The views call it by name off window; if the helper is renamed or
    // dropped, every one of them fails open to the broken native submit.
    expect((string) file_get_contents(base_path('resources/js/app.js')))
        ->toContain('window.beatraxSubmitPostForm');
});
