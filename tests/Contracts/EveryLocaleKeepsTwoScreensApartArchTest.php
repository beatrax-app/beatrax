<?php

declare(strict_types=1);

// Dutch titled both /drift and /notifications "Meldingen", so the reader could
// not tell from the header which screen they had opened. Parity cannot see it:
// both keys exist and both are translated. What is wrong is the relationship
// between two values, which only English fixes — it names those screens apart,
// and a locale is not free to merge them. Where a language has one word for
// both (a plural equal to its singular), the name carries a distinguishing
// word instead.
//
// Both keys, because fixing page_title alone left the defect on screen: the
// tab read "Afwijkingswaarschuwingen" while the h1 under it still said
// "Meldingen", and this test passed. page_title is what the window says;
// heading is what the reader sees.

/** @return array<string, array<string, string>> locale => "Module/file" => value */
function pageTitlesByLocale(string $key = 'page_title'): array
{
    $titles = [];

    foreach (glob(base_path('Modules/*/Resources/lang/*/*.php')) ?: [] as $file) {
        if (preg_match('#/Modules/([^/]+)/Resources/lang/([^/]+)/([^/]+)\.php$#', $file, $match) !== 1) {
            continue;
        }

        $lines = require $file;
        if (! is_array($lines) || ! isset($lines[$key]) || ! is_string($lines[$key])) {
            continue;
        }

        $titles[$match[2]][$match[1].'/'.$match[3]] = $lines[$key];
    }

    return $titles;
}

/**
 * The pairs one locale merges: two slots English names apart that this locale
 * gives one name. Named and taking its two maps so the control below drives the
 * same comparison the walk drives.
 *
 * @param  array<string, string>  $english
 * @param  array<string, string>  $translated
 * @return list<string>
 */
function localeMergedScreens(string $locale, array $english, array $translated): array
{
    $merged = [];
    $slots = array_values(array_intersect(array_keys($english), array_keys($translated)));

    foreach ($slots as $index => $one) {
        foreach (array_slice($slots, $index + 1) as $other) {
            if ($english[$one] === $english[$other] || $translated[$one] !== $translated[$other]) {
                continue;
            }

            $merged[] = sprintf(
                '%s: %s and %s are both "%s" (en: "%s" and "%s")',
                $locale, $one, $other, $translated[$one], $english[$one], $english[$other]
            );
        }
    }

    return $merged;
}

it('never gives one locale the same name for two screens English names apart', function (string $key): void {
    $titles = pageTitlesByLocale($key);
    $english = $titles['en'] ?? [];

    // A screen is a lang file that names a page. `heading` also labels cards
    // and tiles, and English distinguishes those by context rather than by
    // destination — the drift page is headed "Alerts" while the dashboard card
    // pointing at it says "Drift alerts", and neither is a second screen.
    $screens = pageTitlesByLocale()['en'] ?? [];
    $english = array_intersect_key($english, $screens);

    expect(count($english))->toBeGreaterThan(20, 'No English page titles were found to compare against.');

    // 26 languages ship. A run that read one of them compares English with
    // itself and reports every locale as keeping its screens apart.
    expect(count($titles))->toBeGreaterThan(20, 'Almost no locale was read, so this rule compared nothing.');

    $merged = [];

    foreach ($titles as $locale => $translated) {
        if ($locale === 'en') {
            continue;
        }

        foreach (localeMergedScreens($locale, $english, $translated) as $one) {
            $merged[] = $one;
        }
    }

    sort($merged);

    expect($merged)->toBe(
        [],
        "These locales give two different screens one {$key}:\n  ".implode("\n  ", $merged)
    );
})->with(['page_title', 'heading']);

// The comparison is what both datasets get their verdict from, and one that
// found nothing would report every locale as keeping its screens apart.
it('reads a merge only where English named the two apart', function (): void {
    $english = [
        'DriftAlerts/pages' => 'Alerts',
        'Notifications/pages' => 'Notifications',
        'Ledger/pages' => 'Transactions',
    ];

    $dutch = [
        'DriftAlerts/pages' => 'Meldingen',
        'Notifications/pages' => 'Meldingen',
        'Ledger/pages' => 'Transacties',
    ];

    expect(localeMergedScreens('nl', $english, $dutch))->toBe([
        'nl: DriftAlerts/pages and Notifications/pages are both "Meldingen" (en: "Alerts" and "Notifications")',
    ]);

    // English names these two the same, so a locale is not free to keep them
    // apart and is certainly not wrong for merging them.
    $sameInEnglish = ['a/pages' => 'Alerts', 'b/pages' => 'Alerts'];

    expect(localeMergedScreens('nl', $sameInEnglish, ['a/pages' => 'Meldingen', 'b/pages' => 'Meldingen']))->toBe(
        [],
        'English names these two screens the same, so a locale merging them is following English rather than defying it.'
    );
    expect(localeMergedScreens('nl', $english, [
        'DriftAlerts/pages' => 'Afwijkingswaarschuwingen',
        'Notifications/pages' => 'Meldingen',
        'Ledger/pages' => 'Transacties',
    ]))->toBe([], 'A locale that keeps the two screens apart must not be reported as merging them.');
});
