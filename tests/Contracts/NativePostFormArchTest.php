<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-native-post-form-in-the-mobile-shell
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
        foreach (MarkupSource::elements($contents, 'form') as $form) {
            if (strtoupper((string) $form->attribute('method')) !== 'POST') {
                continue;
            }

            if (! str_contains($form->startTag, 'beatraxSubmitPostForm')) {
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

it('submits those forms as JSON', function (): void {
    // The mobile shell forwards ONLY JSON request bodies — urlencoded and
    // multipart both arrive with an empty input bag, so the route reads
    // nothing and redirects back with a 200. Nothing else catches this: the
    // request succeeds, and the control is simply inert on device.
    $helper = (string) file_get_contents(base_path('resources/js/app.js'));
    $body = (string) mb_strstr($helper, 'window.beatraxSubmitPostForm');

    expect($body)->toContain("'Content-Type': 'application/json'")
        ->and($body)->toContain('JSON.stringify')
        ->and($body)->not->toContain('application/x-www-form-urlencoded');
});
