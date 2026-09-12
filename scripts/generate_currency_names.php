<?php

declare(strict_types=1);

/*
 * Generate Modules/Ledger/Resources/currency-names.json — the display name of
 * every currency the bundled FX snapshot can price, in every locale the app
 * ships.
 *
 * The names come from ICU, which already knows all of them in all twenty-six
 * languages. They are transcribed into the repository rather than read from
 * ext-intl at render time for the reason Locale::groupMark() and its siblings
 * are: the mobile PHP build bundles ICU with English-only locale data, so on
 * device the library answers every non-English lookup with the English name
 * and no error. A phone naming a currency differently from the desktop beside
 * it is the defect, and a silent English name in a Dutch list is the defect
 * this repository has a guard against.
 *
 * The set is the snapshot's, because a currency the install cannot price is a
 * currency no roll-up can render in. Re-run this after replacing
 * Modules/FX/Resources/rates-snapshot.json; ACurrencyIsNamedInEveryLocaleTest
 * fails while the two disagree.
 *
 * Run it on a host whose ICU carries full locale data:
 *     php scripts/generate_currency_names.php
 */

const REPO_ROOT = __DIR__.'/..';

const SNAPSHOT = REPO_ROOT.'/Modules/FX/Resources/rates-snapshot.json';

const TARGET = REPO_ROOT.'/Modules/Ledger/Resources/currency-names.json';

/*
 * The snapshot quotes everything against the euro, so EUR earns no row of its
 * own and is priceable by being the base.
 */
const SNAPSHOT_BASE = 'EUR';

/*
 * The locale an app locale reads its ICU data under. Two of the twenty-six
 * differ, and both silently: ICU keeps Norwegian under `no` and leaves `nb`
 * holding nothing but a parent pointer, so a lookup by the app's own code
 * returns no name at all; and ICU's `sr` is Cyrillic where this app ships
 * Serbian in Latin script (Locale::Sr, and the script is pinned by
 * ALocaleIsWrittenInTheScriptItShipsInArchTest), so the Cyrillic names would
 * be the wrong script rather than a missing one.
 */
const ICU_LOCALE = [
    'nb' => 'no',
    'sr' => 'sr_Latn',
];

/** @return list<string> every locale the app ships, as Modules/Core's registry declares them */
function shippedLocales(): array
{
    $source = (string) file_get_contents(REPO_ROOT.'/Modules/Core/Public/Enums/Locale.php');

    if (preg_match_all("/case [A-Z][a-z]* = '([a-z]{2})';/", $source, $found) === false) {
        fail('Could not read the locale registry.');
    }

    $locales = $found[1];
    sort($locales);

    if (count($locales) < 26) {
        fail('The locale registry answered '.count($locales).' locales, which is too few to be it.');
    }

    return $locales;
}

/** @return list<string> every currency code the bundled snapshot can price */
function priceableCodes(): array
{
    /** @var array{rates?: array<string, string>} $snapshot */
    $snapshot = json_decode((string) file_get_contents(SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);

    $codes = array_keys($snapshot['rates'] ?? []);
    $codes[] = SNAPSHOT_BASE;
    $codes = array_values(array_unique($codes));
    sort($codes);

    return $codes;
}

/** @return array<string, string> code => the name ICU's own data for this locale declares */
function namesDeclaredBy(string $locale, array $codes): array
{
    $bundle = ResourceBundle::create(ICU_LOCALE[$locale] ?? $locale, 'ICUDATA-curr', false);

    if ($bundle === null) {
        fail($locale.': ICU on this host carries no currency data for it. Run this where ICU has full locale data.');
    }

    /*
     * Fallback is off on purpose. With it on, a currency the locale does not
     * name resolves up the chain to root and comes back in English, which is
     * exactly the name that must not be written into another language's file.
     */
    $declared = $bundle['Currencies'] ?? null;

    $names = [];
    foreach ($codes as $code) {
        $entry = $declared === null ? null : ($declared[$code] ?? null);
        $name = $entry === null ? null : ($entry[1] ?? null);

        if (! is_string($name) || trim($name) === '') {
            fail($locale.': ICU declares no name for '.$code.'.');
        }

        $names[$code] = $name;
    }

    return $names;
}

function fail(string $reason): never
{
    fwrite(STDERR, 'generate_currency_names: '.$reason.PHP_EOL);
    exit(1);
}

if (! extension_loaded('intl')) {
    fail('ext-intl is not loaded, so there is nothing to read the names out of.');
}

$codes = priceableCodes();
$names = [];

foreach (shippedLocales() as $locale) {
    $names[$locale] = namesDeclaredBy($locale, $codes);
}

/*
 * A positive control. Every step above answers something for a host whose ICU
 * data is filtered to English, because English is the one language such a host
 * does have — and it would write twenty-six identical English files without a
 * word of complaint.
 */
if ($names['nl']['SEK'] === $names['en']['SEK']) {
    fail('Dutch and English named SEK identically, so this host is answering every locale in English.');
}

file_put_contents(TARGET, json_encode($names, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

echo 'Wrote ', count($codes), ' currencies in ', count($names), " locales.\n";
