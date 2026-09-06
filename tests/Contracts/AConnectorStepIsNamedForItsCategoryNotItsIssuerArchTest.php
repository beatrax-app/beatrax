<?php

declare(strict_types=1);

// The card step was titled "Your credit card (ICS)" and the welcome tile that
// previews it said the same, so ICS read as the category rather than as the one
// issuer whose statement the parser happens to read. A reader holding any other
// card was told, by the heading of a step they cannot complete, that their card
// is not a credit card.
//
// The correction is not to stop naming ICS. IcsPdfAdapter reads a Dutch-language
// Mijn ICS statement and nothing else — Dutch month names, "Af"/"Bij" amount
// markers, EUR settlement — so a step that named no issuer would leave every
// reader guessing which PDFs are worth downloading. The category names the step;
// the issuer is stated inside it.

/**
 * @return list<string> every locale the wizard ships
 */
function connectorLocales(): array
{
    $locales = [];
    foreach (glob(base_path('Modules/Onboarding/Resources/lang/*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $locales[] = basename($dir);
    }
    sort($locales);

    return $locales;
}

/**
 * @return array<string, string> key path => line
 */
function connectorLinesIn(string $file): array
{
    if (! is_file($file)) {
        return [];
    }

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

/** the headings that name what a step is, as file => the key paths inside it */
const CONNECTOR_CATEGORY_HEADINGS = [
    'connect_card' => ['eyebrow'],
    'welcome' => ['card_title'],
];

it('names the card step after the category, never after the one issuer it reads', function (): void {
    $locales = connectorLocales();

    // The wizard ships twenty-six languages. A glob that answered nothing would
    // leave every case below reading no line at all and reporting a clean tree.
    expect(count($locales))->toBeGreaterThan(
        20,
        'The walk found '.count($locales).' wizard locales, which is too few to be the set this app ships.',
    );

    $offenders = [];
    $missing = [];

    foreach ($locales as $locale) {
        foreach (CONNECTOR_CATEGORY_HEADINGS as $group => $keys) {
            $file = base_path("Modules/Onboarding/Resources/lang/{$locale}/{$group}.php");
            $lines = connectorLinesIn($file);
            foreach ($keys as $key) {
                // A key that has moved reads back as the empty string, which
                // names no issuer and passes — so the absence is the offence.
                if (! isset($lines[$key])) {
                    $missing[] = "{$locale} {$group}.{$key}";

                    continue;
                }

                if (preg_match('/\bICS\b/', $lines[$key]) !== 1) {
                    continue;
                }

                $offenders[] = "{$locale} {$group}.{$key}: {$lines[$key]}";
            }
        }
    }

    sort($missing);
    sort($offenders);

    expect($missing)->toBe([], implode("\n  ", [
        'A heading this rule reads is gone, so the rule is checking nothing where it stands:',
        ...$missing,
    ]));

    expect($offenders)->toBe([], implode("\n  ", [
        'A heading naming the category named one issuer instead. Offenders:',
        ...$offenders,
    ]));
});

it('still tells the reader which issuer the card step can read', function (): void {
    $silent = [];
    $locales = connectorLocales();

    expect(count($locales))->toBeGreaterThan(
        20,
        'The walk found '.count($locales).' wizard locales, which is too few to be the set this app ships.',
    );

    foreach ($locales as $locale) {
        $body = implode(' ', connectorLinesIn(base_path("Modules/Onboarding/Resources/lang/{$locale}/connect_card.php")));
        if (str_contains($body, 'ICS')) {
            continue;
        }

        $silent[] = $locale;
    }

    expect($silent)->toBe([], implode("\n  ", [
        'The card step named no issuer at all, which is vaguer than the bug it replaced:',
        'a reader cannot tell whether their PDFs are worth downloading. Locales:',
        ...$silent,
    ]));
});
