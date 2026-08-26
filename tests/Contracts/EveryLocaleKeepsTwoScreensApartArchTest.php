<?php

declare(strict_types=1);

// Dutch titled both /drift and /notifications "Meldingen", so the reader could
// not tell from the header which screen they had opened. Parity cannot see it:
// both keys exist and both are translated. What is wrong is the relationship
// between two values, which only English fixes — it names those screens
// "Drift Alerts" and "Notifications", and a locale is not free to merge them.
// Where a language has one word for both (a plural equal to its singular), the
// title has to carry a distinguishing word instead.

/** @return array<string, array<string, string>> locale => "Module/file" => page title */
function pageTitlesByLocale(): array
{
    $titles = [];

    foreach (glob(base_path('Modules/*/Resources/lang/*/*.php')) ?: [] as $file) {
        if (preg_match('#/Modules/([^/]+)/Resources/lang/([^/]+)/([^/]+)\.php$#', $file, $match) !== 1) {
            continue;
        }

        $lines = require $file;
        if (! is_array($lines) || ! isset($lines['page_title']) || ! is_string($lines['page_title'])) {
            continue;
        }

        $titles[$match[2]][$match[1].'/'.$match[3]] = $lines['page_title'];
    }

    return $titles;
}

it('never gives one locale the same title for two screens English names apart', function (): void {
    $titles = pageTitlesByLocale();
    $english = $titles['en'] ?? [];

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
        "These locales title two different screens identically:\n  ".implode("\n  ", $merged)
    );
});
