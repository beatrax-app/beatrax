<?php

declare(strict_types=1);

// Parity asks whether a locale said something for every key. This asks whether
// what it said is that locale's own words: 277 lines were byte-identical to the
// English while five or more sibling locales had translated the same key.
// @link ../../.docs/conventions/english-a-locale-is-allowed-to-keep.md

// How many sibling locales must have translated a key before this rule reads
// anything into one locale leaving it alone. Below it the key is more likely a
// symbol nobody translates than a line one locale forgot.
const FROZEN_LINE_SIBLINGS = 5;

// Every English value a locale is allowed to keep, grouped by the reason it is
// allowed to. A `null` allows it in any locale; a list allows it only in the
// locales named, because the reason there is about those locales rather than
// about the word.
const FROZEN_LINE_PINS = [
    'an acronym, an initialism, or an abbreviation every one of these languages writes the same way' => [
        'args' => null,
        'ECB' => null,
        'Est. :date ·' => null,
        'host os' => null,
        'info' => null,
        'Max' => null,
        'Min' => null,
        'OK' => null,
        'PIN' => null,
        'txn' => null,
        'vs :label' => null,
        '±1%' => null,
        '±2%' => null,
        '±10%' => null,
        '±25%' => null,
        '±50%' => null,
    ],
    'a proper noun — a country, a currency, a company, or a name this product gave something' => [
        'Artisan runner' => null,
        'Austria' => null,
        'Belgium' => null,
        'Bulgaria' => null,
        'Canada' => null,
        'Cyprus' => null,
        'Dev Console' => null,
        'Dev Console — Beatrax' => null,
        'Download beatrax-tax-:year.csv' => null,
        'Download beatrax-tax-:year.pdf' => null,
        'Estonia' => null,
        'Finland' => null,
        'France' => null,
        'Latvia' => null,
        'Luxembourg' => null,
        'Malta' => null,
        'Open Azure Portal' => null,
        'Open Google Cloud Console' => null,
        'Portugal' => null,
        'Romania' => null,
        'Slovakia' => null,
        'Slovenia' => null,
        'via Enable Banking' => null,
    ],
    'the label the provider prints in its own console, which the reader is matching against their screen' => [
        'Application (client) ID' => null,
        'Client ID' => null,
        'Client secret' => null,
        'Client secret value' => null,
        'Redirect URI' => null,
    ],
    'the word this locale actually uses — a loanword it took whole, or a cognate spelled the same way' => [
        '(card)' => null,
        '(optional)' => null,
        ', :count active' => null,
        '1 minute' => null,
        '1 week' => null,
        '5 minutes' => null,
        '15 minutes' => null,
        '30 minutes' => null,
        ':count budget|:count budgets' => null,
        ':count hint|:count hints' => null,
        ':count import|:count imports' => null,
        ':count item|:count items' => null,
        ':count over budget' => null,
        ':count record|:count records' => null,
        ':count source|:count sources' => null,
        ':count transaction|:count transactions' => null,
        ':fetched / ~:count message|:fetched / ~:count messages' => null,
        'Action' => null,
        'Action :number' => null,
        'Actions' => null,
        'Aggregator' => null,
        'Aliases' => null,
        'App' => null,
        'argument|arguments' => null,
        'Audit' => null,
        'Backups:' => null,
        'Bank' => null,
        'Base' => null,
        'Batch' => null,
        'batches' => null,
        'Batches' => null,
        'Budget' => null,
        'Budgets' => null,
        'Buffer: :amount' => null,
        'Calendar' => null,
        'Cloud / Software' => null,
        'Commission' => null,
        'Community' => null,
        'Condition :number' => null,
        'Conditions' => null,
        'Contact' => null,
        'Dashboard' => null,
        'Database:' => null,
        'DATA IN' => null,
        'Date' => null,
        'Description' => null,
        'Email' => null,
        'envelope' => null,
        'Error' => null,
        'error' => null,
        'extensions' => null,
        'file' => null,
        'File' => null,
        'Filters' => null,
        'Format' => null,
        'global' => null,
        'Help' => null,
        'Hints' => null,
        'Hints →' => null,
        'Import' => null,
        'Imports' => null,
        'In' => null,
        'Irregular' => null,
        'local' => null,
        'Logs' => null,
        'Migration' => null,
        'Migrations' => null,
        'Minute' => null,
        'Name' => null,
        'Navigation' => null,
        'Net' => null,
        'net' => null,
        'no' => null,
        'Note' => null,
        'Notifications' => null,
        'Occurrences' => null,
        'Offline' => null,
        'Online' => null,
        'Open' => null,
        'open' => null,
        'Open banking' => null,
        'Optional' => null,
        'Original' => null,
        'Passphrase' => null,
        'Password' => null,
        'Pause' => null,
        'Period' => null,
        'Personal' => null,
        'Preview' => null,
        'Recent' => null,
        'Region' => null,
        'Runtime' => null,
        'Scenario' => null,
        'Source' => null,
        'SQL panel' => null,
        'Stable' => null,
        'Status' => null,
        'Streaming' => null,
        'Subtotal' => null,
        'System' => null,
        'Table' => null,
        'Tables' => null,
        'Tag' => null,
        'Tolerance' => null,
        'Tolerance: :tolerance' => null,
        'Total' => null,
        'Total :amount' => null,
        'Transaction' => null,
        'Transactions' => null,
        'Transfer' => null,
        'Transport' => null,
        'Triage' => null,
        'Type' => null,
        'version' => null,
        ' — optional' => null,
        '→ file:' => null,
        '↗ transaction' => null,
    ],
    'a term this locale\'s own neighbouring copy also keeps in English, so translating this one line would leave the page speaking two vocabularies' => [
        'DESTRUCTIVE' => ['cs', 'da', 'de', 'es', 'fi', 'fr', 'hu', 'it', 'nb', 'nl', 'pt', 'ro', 'sk', 'sv', 'tr'],
        'Exit' => ['da', 'de', 'es', 'it', 'nb', 'nl', 'sv', 'tr'],
        'exit' => ['da', 'de', 'es', 'it', 'nb', 'nl', 'sv', 'tr'],
        'ON' => ['it'],
        'Queue' => ['de'],
        'Queue :queue · Worker :worker' => ['de', 'el', 'sk'],
        'SAFE' => ['cs', 'da', 'de', 'es', 'fi', 'fr', 'hu', 'it', 'nb', 'nl', 'pt', 'ro', 'sk', 'sv', 'tr'],
    ],
];

/** @return array<string, string> every leaf of a translation group, keyed by its dotted path */
function frozenLineFlatten(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat += frozenLineFlatten($value, $path);

            continue;
        }

        $flat[$path] = (string) $value;
    }

    return $flat;
}

/**
 * The lines a locale left byte-identical to English while FROZEN_LINE_SIBLINGS
 * or more other locales translated the same key.
 *
 * @param  array<string, string>  $english
 * @param  array<string, array<string, string>>  $locales
 * @return list<array{locale: string, key: string, en: string}>
 */
function frozenLinesIn(array $english, array $locales, int $siblings): array
{
    $sites = [];

    foreach ($english as $key => $value) {
        $frozen = [];
        $translated = 0;

        foreach ($locales as $locale => $lines) {
            if (! array_key_exists($key, $lines)) {
                continue;
            }

            if ($lines[$key] === $value) {
                $frozen[] = $locale;

                continue;
            }

            $translated++;
        }

        if ($translated < $siblings) {
            continue;
        }

        foreach ($frozen as $locale) {
            $sites[] = ['locale' => $locale, 'key' => $key, 'en' => $value];
        }
    }

    return $sites;
}

/** @return list<string> the locales this app ships translations for, English excluded */
function frozenLineLocales(): array
{
    $entries = scandir(base_path('lang'));

    return array_values(array_diff($entries === false ? [] : $entries, ['.', '..']));
}

/**
 * Every frozen line in the tree, plus what the walk had to read to find them.
 *
 * @return array{sites: list<array{locale: string, key: string, en: string}>, namespaces: int, groups: int, keys: int}
 */
function frozenLineSurvey(): array
{
    $locales = frozenLineLocales();
    $sites = [];
    $namespaces = 0;
    $groups = 0;
    $keys = 0;

    foreach (Lang::getLoader()->namespaces() as $namespace => $path) {
        if (! is_dir($path.'/en')) {
            continue;
        }

        $namespaces++;

        foreach (glob($path.'/en/*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $english = Lang::get($namespace.'::'.$group, [], 'en');

            if (! is_array($english)) {
                continue;
            }

            $groups++;
            $english = frozenLineFlatten($english);
            $keys += count($english);
            $translations = [];

            foreach ($locales as $locale) {
                // A group file a locale does not ship at all resolves to the
                // English array, which would read as every key frozen. Parity
                // owns that shape; this rule reads only files that are there.
                if (! is_file($path.'/'.$locale.'/'.$group.'.php')) {
                    continue;
                }

                $lines = Lang::get($namespace.'::'.$group, [], $locale);
                $translations[$locale] = is_array($lines) ? frozenLineFlatten($lines) : [];
            }

            foreach (frozenLinesIn($english, $translations, FROZEN_LINE_SIBLINGS) as $site) {
                $sites[] = ['locale' => $site['locale'], 'key' => $namespace.'::'.$group.'.'.$site['key'], 'en' => $site['en']];
            }
        }
    }

    return ['sites' => $sites, 'namespaces' => $namespaces, 'groups' => $groups, 'keys' => $keys];
}

/** @return array<string, list<string>|null> every declared value, flattened out of its reason */
function frozenLineDeclared(): array
{
    $declared = [];

    foreach (FROZEN_LINE_PINS as $entries) {
        foreach ($entries as $value => $locales) {
            $declared[(string) $value] = $locales;
        }
    }

    return $declared;
}

/** Whether this locale is allowed to keep this English value. */
function frozenLineIsDeclared(string $value, string $locale): bool
{
    $declared = frozenLineDeclared();

    if (! array_key_exists($value, $declared)) {
        return false;
    }

    $locales = $declared[$value];

    return $locales === null || in_array($locale, $locales, true);
}

it('translates a line the other locales translated, or declares why this one keeps the English', function (): void {
    $survey = frozenLineSurvey();

    // A renamed lang directory, a loader that stopped answering, a glob that
    // found nothing: each reports a clean tree from a walk that read nothing.
    expect($survey['namespaces'])->toBeGreaterThan(25, 'Read '.$survey['namespaces'].' translation namespaces, too few for an empty offender list to mean anything.');
    expect($survey['groups'])->toBeGreaterThan(140, 'Read '.$survey['groups'].' groups, too few to have opened the modules this rule names.');
    expect($survey['keys'])->toBeGreaterThan(3800, 'Read '.$survey['keys'].' English keys, too few to have opened the copy this rule reads.');
    expect(count(frozenLineLocales()))->toBeGreaterThan(24, 'Found fewer shipped locales than this app ships.');

    $offenders = [];

    foreach ($survey['sites'] as $site) {
        if (frozenLineIsDeclared($site['en'], $site['locale'])) {
            continue;
        }

        $offenders[] = $site['locale'].' · '.$site['key'].' — '.json_encode($site['en'], JSON_UNESCAPED_UNICODE);
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'These lines are byte-identical to the English while at least '.FROZEN_LINE_SIBLINGS.' other',
        'locales translated the same key:',
        ...$offenders,
        '',
        'Parity is blind to this and always will be: the key exists, the',
        'placeholders match and the plural segments count the same. The reader',
        'gets a Dutch page with an English word in it, which reads as a missing',
        'feature rather than as a missing translation — and where the line is an',
        'aria label, a screen reader pronounces English inside a Dutch document.',
        '',
        'Translate it from what the tree already holds, never free-hand: the same',
        'English word translated at another key in the same locale, or a sibling',
        'in the same array that locale did translate. Where the English IS the',
        'word that language uses — an acronym, a proper noun, a loanword, the',
        'label a provider prints in its own console — declare it in',
        'FROZEN_LINE_PINS under the reason it belongs to, and name the locales',
        'when the reason is about them rather than about the word.',
    ]));
});

it('holds every declared value to a line that is still standing in English', function (): void {
    expect(FROZEN_LINE_PINS)->not->toBe([], 'The declaration map is empty, so this rule proves nothing about it.');

    $reached = [];

    foreach (frozenLineSurvey()['sites'] as $site) {
        $reached[$site['en']][$site['locale']] = true;
    }

    $stale = [];

    foreach (frozenLineDeclared() as $value => $locales) {
        if (! array_key_exists($value, $reached)) {
            $stale[] = json_encode($value, JSON_UNESCAPED_UNICODE).' — no locale keeps this in English any more';

            continue;
        }

        foreach ($locales ?? [] as $locale) {
            if (! array_key_exists($locale, $reached[$value])) {
                $stale[] = json_encode($value, JSON_UNESCAPED_UNICODE).' — '.$locale.' no longer keeps this in English';
            }
        }
    }

    sort($stale);

    expect($stale)->toBe([], implode("\n", [
        'These declarations no longer describe the tree:',
        ...$stale,
        '',
        'A declaration is a judgment under review, not a waiver. One nothing',
        'reaches goes on excusing a word that may since have been translated',
        'everywhere, and it is the line a future reader trusts instead of looking.',
        'Delete the entry.',
    ]));
});

it('reads a locale that kept the English, and is not fooled by one that translated it', function (): void {
    $english = ['kept' => 'Tier', 'moved' => 'Tier'];
    $locales = [
        'nl' => ['kept' => 'Tier', 'moved' => 'Niveau'],
        'de' => ['kept' => 'Stufe', 'moved' => 'Stufe'],
        'fr' => ['kept' => 'Niveau', 'moved' => 'Niveau'],
        'it' => ['kept' => 'Livello', 'moved' => 'Livello'],
        'pl' => ['kept' => 'Poziom', 'moved' => 'Poziom'],
        'sv' => ['kept' => 'Nivå', 'moved' => 'Nivå'],
    ];

    expect(frozenLinesIn($english, $locales, 5))
        ->toBe([['locale' => 'nl', 'key' => 'kept', 'en' => 'Tier']], 'the one locale that kept the English word is the whole rule');

    // The threshold is what keeps a symbol nobody translates out of the report.
    expect(frozenLinesIn($english, $locales, 6))->toBe([], 'a key too few siblings translated says nothing about the one that did not');

    expect(frozenLinesIn(['gone' => 'Tier'], ['nl' => []], 5))->toBe([], 'a key a locale does not carry is parity\'s business, not this rule\'s');
});

it('declares a value against the locale it was declared for, and no other', function (): void {
    expect(frozenLineIsDeclared('ECB', 'nl'))->toBeTrue('a value declared with no locale list stands in every locale');
    expect(frozenLineIsDeclared('Queue', 'de'))->toBeTrue('German keeps Queue through its own Dev Console copy');
    expect(frozenLineIsDeclared('Queue', 'nl'))->toBeFalse('a locale outside the list is still reported');
    expect(frozenLineIsDeclared('Backspace', 'nl'))->toBeFalse('a value nobody declared is reported');
});

it('keeps the tier names the surrounding copy of every locale that keeps them also names in English', function (): void {
    $locales = frozenLineDeclared()['SAFE'] ?? [];

    expect($locales)->not->toBe([], 'The tier declaration names no locales, so this rule proves nothing about it.');

    $contradicting = [];

    foreach ($locales ?? [] as $locale) {
        $subtitle = Lang::get('dev::runner.subtitle', [], $locale);

        if (! is_string($subtitle) || ! str_contains($subtitle, 'SAFE') || ! str_contains($subtitle, 'DESTRUCTIVE')) {
            $contradicting[] = $locale.' — '.(is_string($subtitle) ? $subtitle : 'no subtitle');
        }
    }

    expect($contradicting)->toBe([], implode("\n", [
        'These locales keep SAFE and DESTRUCTIVE on the badge and no longer name',
        'them in the sentence above it:',
        ...$contradicting,
        '',
        'The badge stands in English because that page already speaks of SAFE and',
        'DESTRUCTIVE commands in this language. Once the sentence stops doing',
        'that, the badge is one word of English on a translated page and the',
        'declaration that excused it has stopped being true — translate the badge',
        'and drop the locale from FROZEN_LINE_PINS.',
    ]));
});
