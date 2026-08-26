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

    $merged = [];

    foreach ($titles as $locale => $screens) {
        if ($locale === 'en') {
            continue;
        }

        $slots = array_values(array_intersect(array_keys($english), array_keys($screens)));

        foreach ($slots as $index => $one) {
            foreach (array_slice($slots, $index + 1) as $other) {
                if ($english[$one] === $english[$other] || $screens[$one] !== $screens[$other]) {
                    continue;
                }

                $merged[] = sprintf(
                    '%s: %s and %s are both "%s" (en: "%s" and "%s")',
                    $locale, $one, $other, $screens[$one], $english[$one], $english[$other]
                );
            }
        }
    }

    sort($merged);

    expect($merged)->toBe(
        [],
        "These locales give two different screens one {$key}:\n  ".implode("\n  ", $merged)
    );
})->with(['page_title', 'heading']);
