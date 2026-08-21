<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-bare-envsafe-area-inset--on-android
 */
function safeAreaTemplates(): Finder
{
    return Finder::create()
        ->files()
        ->in([base_path('Modules'), base_path('resources/views')])
        ->name('*.blade.php');
}

it('never reads env(safe-area-inset-*) outside the seam that fills it in', function (): void {
    $offenders = [];

    foreach (safeAreaTemplates() as $file) {
        $body = preg_replace('/\{\{--.*?--\}\}/s', '', (string) $file->getContents()) ?? '';

        if (str_contains($body, 'env(safe-area-inset-')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These templates read env(safe-area-inset-*) directly:',
        ...$offenders,
        '',
        'iOS fills env() in; the Android shell leaves it at zero and injects',
        '--inset-* onto :root instead. A bare env() is therefore a padding of',
        'zero on Android — no error, no warning, content simply under the system',
        'bars. Use var(--safe-top|bottom|left|right), which resources/css/app.css',
        'defines as max() of the two sources and is correct on both platforms.',
    ]));
});

it('keeps the seam those templates depend on', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $missing = [];

    foreach (['top', 'bottom', 'left', 'right'] as $edge) {
        $rule = "--safe-{$edge}: max(env(safe-area-inset-{$edge}, 0px), var(--inset-{$edge}, 0px))";

        if (! str_contains($css, $rule)) {
            $missing[] = $rule;
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'resources/css/app.css no longer defines these as max() of both sources:',
        ...$missing,
        '',
        'Every template padding through var(--safe-*) depends on this rule to be',
        'correct on both platforms. Losing an arm of the max() does not fail here',
        'or anywhere else — it just stops padding on the platform it dropped.',
    ]));
});

it('pads a horizontal inset per edge rather than mirroring one of them', function (): void {
    $offenders = [];

    foreach (safeAreaTemplates() as $file) {
        if (preg_match('/px-\[var\(--safe-(left|right)\)\]/', (string) $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These templates set both horizontal paddings from one edge:',
        ...$offenders,
        '',
        'px-* writes padding-left AND padding-right. Paired with a following',
        'pr-*, the result is right only because Tailwind happens to emit pr after',
        'px; the left inset is otherwise mirrored onto the right. In landscape on',
        'a notched phone the two edges differ. Use pl-* with pr-*.',
    ]));
});
