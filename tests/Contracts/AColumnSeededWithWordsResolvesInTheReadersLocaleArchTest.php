<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Symfony\Component\Yaml\Yaml;

// A seeder writes words into a column, and every screen afterwards reads that
// column back — in the seeder's language, whoever is reading. `currencies.name`
// offered a Dutch reader "Pound Sterling"; the tax corpus seeded "Zorgkosten"
// for an English one. Neither was reachable by the translation guards, which
// read lang files and call sites and cannot see a string that arrives as data,
// and the tax one was not even a PHP literal — it was bundled YAML.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#english-written-into-a-column-a-screen-reads-back

// The columns a reference row is seeded with and a screen prints. `slug` and
// `code` are not here: a machine word is not what is being looked for.
const SEEDED_WORDING_KEYS = ['name', 'display_name', 'short_name', 'hint', 'label', 'title'];

// Each entry is a file that seeds display wording, and the resolution that
// carries it into the reader's language. The `proves` pattern is re-run: when
// the wiring is torn out the entry stops matching and this fails rather than
// keeping a promise nothing behind it still keeps.
const SEEDED_WORDING_SOURCES = [
    'Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php' => [
        'resolves' => 'categories.slug through categorization::categories, guarded by name_is_default',
        'proves' => "/'slug' => '/",
    ],
    'Modules/Ledger/Database/Seeders/CurrenciesSeeder.php' => [
        'resolves' => 'currencies.code through ledger::currencies',
        'proves' => "/'code' => /",
    ],
    'Modules/Ledger/Database/Migrations/2026_08_19_000001_seed_currencies_on_every_device.php' => [
        'resolves' => 'currencies.code through ledger::currencies',
        'proves' => "/'code' => /",
    ],
    'Modules/Ledger/Database/Migrations/2026_08_29_000003_seed_the_zero_decimal_currency.php' => [
        'resolves' => 'currencies.code through ledger::currencies',
        'proves' => "/'code' => /",
    ],
];

// Each entry names a file whose seeded wording is a proper noun — an institution,
// a card scheme, a shop — which reads the same in every language, and which the
// Counterparties seam already treats as the entity's own words.
const SEEDED_WORDING_PINS = [
    'Modules/Community/Database/Seeders/Demo/DemoCommunityMappingsSeeder.php' => [
        'reason' => 'the registered names behind demo community mappings',
        'proves' => "/'name' => 'Stichting Tuinbouw NL'/",
    ],
    'Modules/EmailScan/Database/Migrations/2026_05_16_020004_create_known_senders_table.php' => [
        'reason' => 'the brands whose receipt mail the scanner recognises',
        'proves' => "/'label' => 'PayPal'/",
    ],
    'Modules/Ledger/Database/Seeders/Demo/DemoAccountsSeeder.php' => [
        'reason' => 'the banks and card schemes the demo account set is drawn from',
        'proves' => "/'name' => 'ASN Bank'/",
    ],
    'Modules/Ledger/Database/Seeders/Demo/DemoTransactionsSeeder.php' => [
        'reason' => 'the shops the demo transactions are spent at',
        'proves' => "/'name' => 'Jumbo'/",
    ],
];

/** @return list<string> every seeder and data migration, relative to the repo root */
function seededWordingFiles(): array
{
    $paths = array_merge(
        glob(base_path('Modules/*/Database/Seeders/*.php')) ?: [],
        glob(base_path('Modules/*/Database/Seeders/*/*.php')) ?: [],
        glob(base_path('Modules/*/Database/Migrations/*.php')) ?: [],
    );

    $files = array_map(static fn (string $path): string => str_replace(base_path().'/', '', $path), $paths);
    sort($files);

    return array_values($files);
}

// How each bundled corpus tree gets its wording into the reader's language.
// A tree is keyed when a row stores something a screen re-resolves it by; the
// two keyed ones differ only in where the wording for that key lives, and the
// arithmetic in each `reason` is why they differ.
const CORPUS_IN_CORPUS = 'the entry carries its own wording, per locale';

const CORPUS_IN_LANG = 'the entry key resolves through a lang group';

const CORPUS_PROPER_NOUN = 'the wording is a proper noun and reads the same everywhere';

const CORPUS_DECLARED_LANGUAGE = 'the prose stays the provider\'s, and the file declares which language that is';

/** @var list<string> the resolutions that mean a row stores a key */
const CORPUS_KEYED = [CORPUS_IN_CORPUS, CORPUS_IN_LANG];

const CORPUS_TREES = [
    'tax' => [
        'resolution' => CORPUS_IN_CORPUS,
        'reason' => 'corpus_key is stored on tax_deduction_categories and read back for display. A lang group would owe 398 entries across 33 jurisdictions a line in all 26 locales — 31,044 strings, 24 locales\' worth of it English pasted into another language\'s file, and an outright failure of ALocaleIsWrittenInTheScriptItShipsIn for el, bg and uk',
    ],
    'bank-fees' => [
        'resolution' => CORPUS_IN_LANG,
        'group' => 'counterparties::components.default_name.',
        'vocabulary' => CounterpartyDefaultName::FEE_KINDS,
        'reason' => '257 entries carry 166 distinct fee words for 18 kinds of charge, so the KIND is what a reader outside the jurisdiction needs. 18 keys x 26 locales is 468 strings and serves every locale; the same corpus carrying its own wording would be 6,682, and even the two-locale contract the tax tree uses would be 514 and leave 24 locales on English forever',
    ],
    'merchants' => [
        'resolution' => CORPUS_PROPER_NOUN,
        'reason' => 'pattern => a trading name — a proper noun that reads the same in every language, and the Counterparties seam already treats it as the entity\'s own words',
    ],
    'government' => [
        'resolution' => CORPUS_PROPER_NOUN,
        'reason' => 'pattern => the registered name of a public body — a proper noun, as above',
    ],
    'support' => [
        'resolution' => CORPUS_DECLARED_LANGUAGE,
        'renders' => 'Modules/Counterparties/Resources/views/livewire/profile-tabs/partials/support-resources.blade.php',
        'proves' => '/lang="\{\{ \$notesLocale->value \}\}"/',
        'reason' => '`notes` is a researched paragraph on how ONE provider cancels, carrying phone numbers, notice periods and postal addresses. 608 of them in 32 files: a lang group owes 15,808 paragraphs, and even the tax tree\'s two-locale contract owes 1,203 more — 420,000 characters of operational fact restated by somebody who cannot verify it. It stays the provider\'s prose, and the card tags it and names the language',
    ],
];

/** @return list<string> the bundled corpus files of every tree resolving the named way */
function seededWordingCorporaResolving(string ...$resolutions): array
{
    $paths = [];
    foreach (CORPUS_TREES as $tree => $entry) {
        if (in_array($entry['resolution'], $resolutions, true)) {
            $paths = array_merge($paths, glob(base_path('resources/corpus/'.$tree.'/*.yaml')) ?: []);
        }
    }
    sort($paths);

    return array_values($paths);
}

/** @return list<array<array-key, mixed>> every entry of every file in the named tree */
function seededWordingEntriesIn(string $tree): array
{
    $entries = [];
    foreach (glob(base_path('resources/corpus/'.$tree.'/*.yaml')) ?: [] as $path) {
        /** @var array<array-key, mixed> $parsed */
        $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        foreach (is_array($parsed['entries'] ?? null) ? $parsed['entries'] : [] as $row) {
            if (is_array($row)) {
                $entries[] = $row;
            }
        }
    }

    return $entries;
}

function seededWordingResolves(string $key): bool
{
    $translator = app(Translator::class);

    foreach ([Locale::DEFAULT, Locale::Nl->value] as $locale) {
        $line = $translator->get($key, [], $locale, false);
        if (! is_string($line) || $line === '' || $line === $key) {
            return false;
        }
    }

    return true;
}

it('names every seeder that writes display wording into a column a screen reads back', function (): void {
    $files = seededWordingFiles();

    // Far under the seeders and data migrations this tree holds. A glob that
    // answered nothing reports no unaccounted seeder, which is the answer a
    // fully-registered tree gives too.
    expect(count($files))->toBeGreaterThan(
        50,
        'The glob found '.count($files).' seeders and data migrations, which is too few to have read Modules/ at all.',
    );

    $pattern = "/'(".implode('|', SEEDED_WORDING_KEYS).")'\s*=>\s*'[^']*\p{L}[^']*'/u";

    $unaccounted = [];
    $reached = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents(base_path($file));
        if (PatternScan::all($pattern, $source)[0] === []) {
            continue;
        }
        if (array_key_exists($file, SEEDED_WORDING_SOURCES) || array_key_exists($file, SEEDED_WORDING_PINS)) {
            $reached[] = $file;

            continue;
        }

        $unaccounted[] = $file;
    }

    expect($unaccounted)->toBe([], implode("\n", [
        'These seed words into a column a screen prints, and nothing here says how a reader',
        'in another language gets them. Give the row a key and resolve it through',
        'SeededDisplayName, then add it to SEEDED_WORDING_SOURCES — or, if the wording is a',
        'proper noun that reads the same in every language, pin it with the reason:',
        '  '.implode("\n  ", $unaccounted),
    ]));

    // An entry the walk no longer reaches excuses nothing, and it reads as a
    // resolution somebody put behind a column that has since stopped being
    // seeded at all. Both registries are held to it.
    $declared = [...array_keys(SEEDED_WORDING_SOURCES), ...array_keys(SEEDED_WORDING_PINS)];
    sort($declared);
    sort($reached);

    expect($reached)->toBe($declared, implode("\n", [
        'A file registered here no longer seeds display wording under any of these keys, so the entry',
        'excuses nothing and the next reader will take it for a decision about the tree. Delete it:',
        '  '.implode("\n  ", array_values(array_diff($declared, $reached))),
    ]));
});

it('keeps every registered resolution and every pin pointing at something', function (): void {
    $stale = [];
    foreach ([SEEDED_WORDING_SOURCES, SEEDED_WORDING_PINS] as $registry) {
        foreach ($registry as $file => $entry) {
            $source = (string) file_get_contents(base_path($file));
            if (PatternScan::all($entry['proves'], $source)[0] === []) {
                $stale[] = $file;
            }
        }
    }

    expect($stale)->toBe([], 'an entry no longer matches the file it describes: '.implode(', ', $stale));
});

it('resolves every default category slug in English and Dutch', function (): void {
    $source = (string) file_get_contents(base_path('Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php'));

    /** @var list<string> $slugs */
    $slugs = array_values(array_unique(PatternScan::all("/'slug'\s*=>\s*'([a-z0-9\-]+)'/", $source)[1]));
    expect($slugs)->not->toBe([], 'The default category seeder declares no slug at all, so this rule resolves nothing.');

    $missing = array_values(array_filter(
        $slugs,
        static fn (string $slug): bool => ! seededWordingResolves('categorization::categories.'.$slug),
    ));

    expect($missing)->toBe([], 'seeded category slugs with no line to resolve to: '.implode(', ', $missing));
});

// Every file registered against `currencies`, not just the seeder: EUR, USD and
// GBP are spelled out in the install seed migration and JPY in its own, and the
// seeder itself reaches two of them through the Currency enum rather than a
// literal. Reading one file would have found two codes and called it complete.
it('resolves every seeded currency code in English and Dutch', function (): void {
    $source = '';
    foreach (array_keys(SEEDED_WORDING_SOURCES) as $file) {
        if (str_contains((string) $file, 'currenc')) {
            $source .= (string) file_get_contents(base_path((string) $file));
        }
    }

    /** @var list<string> $codes */
    $codes = array_values(array_unique(PatternScan::all("/'([A-Z]{3})'/", $source)[1]));
    expect($codes)->toEqualCanonicalizing(['EUR', 'USD', 'GBP', 'JPY']);

    $missing = array_values(array_filter(
        $codes,
        static fn (string $code): bool => ! seededWordingResolves('ledger::currencies.'.strtolower($code)),
    ));

    expect($missing)->toBe([], 'seeded currency codes with no line to resolve to: '.implode(', ', $missing));
});

it('classifies every bundled corpus tree', function (): void {
    $trees = array_values(array_filter(
        scandir(base_path('resources/corpus')) ?: [],
        static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
            && is_dir(base_path('resources/corpus/'.$entry)),
    ));
    sort($trees);

    $declared = array_keys(CORPUS_TREES);
    sort($declared);

    expect($trees)->toBe($declared, implode("\n", [
        'A corpus tree is bundled that this rule has never been told about. Say whether its',
        'entries carry a `key` a row stores and a screen re-resolves. If they do, it owes',
        'English and Dutch wording like the tax corpus; if they do not, say what carries them',
        'into the reader\'s language instead.',
    ]));
});

// The classification has to stay true: a tree resolved some other way that
// grows a `key` has quietly become this rule's subject without being checked.
it('finds no key in a tree that resolves without one', function (): void {
    $keyed = [];
    foreach (seededWordingCorporaResolving(CORPUS_PROPER_NOUN, CORPUS_DECLARED_LANGUAGE) as $path) {
        /** @var array<array-key, mixed> $parsed */
        $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = is_array($parsed['entries'] ?? null) ? $parsed['entries'] : [];
        foreach ($rows as $row) {
            if (isset($row['key'])) {
                $keyed[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    expect($keyed)->toBe([], 'a tree that resolves without a key now carries one: '.implode(', ', $keyed));
});

// Three lists have to agree or a fee row answers with a key: the keys the
// corpus actually uses, the closed vocabulary the seam will accept back off a
// row, and the lines the lang group holds. Checking only the last would pass a
// corpus key no reader ever reaches, because nothing would have looked for it.
it('resolves every key a lang-backed corpus tree uses, in English and Dutch', function (): void {
    $checked = 0;
    foreach (CORPUS_TREES as $tree => $entry) {
        if ($entry['resolution'] !== CORPUS_IN_LANG) {
            continue;
        }

        $used = [];
        foreach (seededWordingEntriesIn($tree) as $row) {
            if (is_string($row['name'] ?? null)) {
                $used[] = is_string($row['key'] ?? null) ? $row['key'] : '(none)';
            }
        }
        $used = array_values(array_unique($used));
        sort($used);

        /** @var list<string> $vocabulary */
        $vocabulary = $entry['vocabulary'];
        $declared = $vocabulary;
        sort($declared);

        expect($used)->toBe($declared, $tree.': the keys the corpus uses are not the vocabulary the seam accepts. A key outside it resolves to nothing and leaves the row in the jurisdiction\'s wording.');

        /** @var string $group */
        $group = $entry['group'];
        $missing = array_values(array_filter(
            $vocabulary,
            static fn (string $key): bool => ! seededWordingResolves($group.$key),
        ));

        expect($missing)->toBe([], $tree.': corpus keys with no line to resolve to: '.implode(', ', $missing));
        $checked++;
    }

    expect($checked)->toBeGreaterThan(
        0,
        'No tree is classified as resolving through a lang group, so this rule compared nothing.',
    );
});

// Prose that stays in the provider's language is only an answer while the
// reader is told which language that is. The file has to name one this app
// ships, and the card that prints the paragraph has to still be tagging it.
it('names a shipped locale on every file whose prose stays the provider\'s', function (): void {
    $offenders = [];
    foreach (CORPUS_TREES as $tree => $entry) {
        if ($entry['resolution'] !== CORPUS_DECLARED_LANGUAGE) {
            continue;
        }

        $files = glob(base_path('resources/corpus/'.$tree.'/*.yaml')) ?: [];
        expect($files)->not->toBe([], $tree.' declares its prose stays the provider\'s and ships no corpus file to check.');

        foreach ($files as $path) {
            /** @var array<array-key, mixed> $parsed */
            $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            $lang = $parsed['lang'] ?? null;
            if (! is_string($lang) || Locale::tryFrom($lang) === null) {
                $offenders[] = str_replace(base_path().'/', '', $path).': lang is '.var_export($lang, true);
            }
        }

        /** @var string $renders */
        $renders = $entry['renders'];
        /** @var string $proves */
        $proves = $entry['proves'];
        $markup = (string) file_get_contents(base_path($renders));
        if (PatternScan::all($proves, $markup)[0] === []) {
            $offenders[] = $renders.': no longer tags the prose with the language the file declares';
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A tree whose prose stays in the provider\'s language owes the reader the name of',
        'that language, and a screen reader the tag. Every file must declare a `lang:` this',
        'app ships a locale for, and the card must still carry it:',
        '  '.implode("\n  ", $offenders),
    ]));
});

// The corpus carries its own wording rather than a lang group: 398 entries
// across 33 jurisdictions would owe a line in all twenty-six shipped locales,
// and twenty-four of those files would be English pasted into another language's
// file. English is required because it is the fallback every other reader lands
// on; Dutch because it is the second locale the product ships.
it('carries English and Dutch wording for every bundled corpus entry', function (): void {
    $corpora = seededWordingCorporaResolving(CORPUS_IN_CORPUS);
    expect($corpora)->not->toBe([], 'No corpus tree carries its own wording any more, so this rule reads nothing.');

    $entries = 0;
    $gaps = [];
    foreach ($corpora as $path) {
        /** @var array<array-key, mixed> $parsed */
        $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        $rel = str_replace(base_path().'/', '', $path);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = is_array($parsed['entries'] ?? null) ? $parsed['entries'] : [];
        foreach ($rows as $row) {
            $entries++;
            $key = is_string($row['key'] ?? null) ? $row['key'] : '?';
            foreach ([Locale::DEFAULT, Locale::Nl->value] as $locale) {
                foreach (['name', 'short_name', 'hint'] as $field) {
                    $value = $row['i18n'][$locale][$field] ?? null;
                    if (! is_string($value) || trim($value) === '') {
                        $gaps[] = $rel.' '.$key.' i18n.'.$locale.'.'.$field;
                    }
                }
            }
        }
    }

    expect($entries)->toBeGreaterThan(
        300,
        'The walk read '.$entries.' corpus entries, which is too few to be the bundled tax corpus.',
    );
    expect($gaps)->toBe([], "corpus entries a reader outside the jurisdiction cannot read:\n  ".implode("\n  ", $gaps));
});
