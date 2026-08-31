<?php

declare(strict_types=1);

// The preview counts the counterparties the export HOLDS; the results screen
// counts the payees the import LINKED, which is a different number whenever a
// payee matched a counterparty the reader already had — 3 against 2 on the
// first run of the nYNAB fixture. Every other figure beside it on that screen
// is a created-count, so the bare noun read as one too.
//
// This does not judge the wording. It keeps the two screens from being tidied
// back into one word, in any locale, without someone meeting the reason first.

/** @return list<string> */
function migrationLocales(): array
{
    $locales = [];

    foreach (glob(base_path('Modules/Migration/Resources/lang/*/results.php')) ?: [] as $file) {
        $locales[] = basename(dirname($file));
    }

    sort($locales);

    return $locales;
}

it('never labels the results payee stat with the bare noun the preview uses', function (): void {
    $locales = migrationLocales();

    // A walk that found nothing would pass while saying nothing.
    expect(count($locales))->toBeGreaterThan(20);

    $offenders = [];

    foreach ($locales as $locale) {
        /** @var array<string, mixed> $results */
        $results = require base_path("Modules/Migration/Resources/lang/{$locale}/results.php");
        /** @var array<string, mixed> $preview */
        $preview = require base_path("Modules/Migration/Resources/lang/{$locale}/preview.php");

        $onResults = $results['stats']['payee'];
        $onPreview = $preview['stats']['payee'];

        if ($onResults === $onPreview) {
            $offenders[] = $locale.' — both screens say "'.$onResults.'"';
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These locales give the two screens one label for two different counts:',
        ...$offenders,
    ]));
});
