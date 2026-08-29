<?php

declare(strict_types=1);

// Dutch had settled on "winkelier" -- Anomaly, Counterparties and Core settings
// all use it, and Core settings headed the shared list "Gedeelde winkelierslijst".
// The Community page headed the same list "Gedeelde merchantlijst" and asked the
// reader to "Help merchants herkennen". Every one of the other 25 locales
// translated the word; Dutch was the only one that did not, and parity could not
// see it because the key was present and the value non-empty.

/**
 * The English source word, then the word the locale already uses for it in
 * production strings. Only terms a locale has genuinely settled: a term still
 * spelled both ways on purpose would flag its own deliberate usage.
 *
 * @return array<string, array<string, string>>
 */
function settledTerms(): array
{
    return [
        'nl' => ['merchant' => 'winkelier'],
    ];
}

/**
 * @return array<string, string>
 */
function flattenedStrings(string $file): array
{
    /** @var array<array-key, mixed> $loaded */
    $loaded = require $file;

    $walk = static function (array $node, string $prefix) use (&$walk): array {
        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $walk($value, $path);

                continue;
            }
            $out[$path] = (string) $value;
        }

        return $out;
    };

    return $walk($loaded, '');
}

it('does not fall back to the English word for a term the locale already translates', function (): void {
    $leaks = [];

    foreach (settledTerms() as $locale => $terms) {
        foreach (glob(base_path("Modules/*/Resources/lang/{$locale}/*.php")) ?: [] as $file) {
            foreach (flattenedStrings($file) as $key => $value) {
                foreach ($terms as $english => $native) {
                    if (preg_match('/\b'.$english.'/iu', $value) !== 1) {
                        continue;
                    }

                    $leaks[] = str_replace(base_path().'/', '', $file)." [{$key}] "
                        ."said \"{$english}\" where {$locale} says \"{$native}\": {$value}";
                }
            }
        }
    }

    sort($leaks);

    expect($leaks)->toBe(
        [],
        "These translated strings use the English term the locale has its own word for:\n  "
        .implode("\n  ", $leaks)
    );
});
