<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-unbound-false-is-the-string-false
 */

// The attributes Flux resolves with ===, so a string never matches.
const STRICTLY_COMPARED_FLUX_ATTRIBUTES = ['dismissible', 'escapable'];

it('binds every boolean flux attribute with a colon (Flux compares them strictly)', function (): void {
    $offenders = [];

    $finder = (new Finder)->files()->in(base_path('Modules'))->name('*.blade.php');

    foreach ($finder as $file) {
        foreach (explode("\n", $file->getContents()) as $number => $line) {
            if (! str_contains($line, 'flux:')) {
                continue;
            }

            foreach (STRICTLY_COMPARED_FLUX_ATTRIBUTES as $attribute) {
                // The colon-less spelling, and only on a tag: the same words
                // appear in the Blade comments explaining these very props.
                if (preg_match('/(?<![:\w-])'.$attribute.'="(true|false)"/', $line) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['These flux attributes are passed as strings, so Flux\'s === check never matches',
            'and the behaviour they ask for is silently skipped. Add the colon:',
            'dismissible="false" -> :dismissible="false". Offenders:'],
        $offenders,
    )));
});
