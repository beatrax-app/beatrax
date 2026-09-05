<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;
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

/** @return list<string> the bundled corpus files, relative to the repo root */
function seededWordingCorpora(): array
{
    $paths = glob(base_path('resources/corpus/*/*.yaml')) ?: [];
    sort($paths);

    return array_values($paths);
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
    expect($files)->not->toBe([]);

    $pattern = "/'(".implode('|', SEEDED_WORDING_KEYS).")'\s*=>\s*'[^']*\p{L}[^']*'/u";

    $unaccounted = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents(base_path($file));
        if (PatternScan::all($pattern, $source)[0] === []) {
            continue;
        }
        if (array_key_exists($file, SEEDED_WORDING_SOURCES) || array_key_exists($file, SEEDED_WORDING_PINS)) {
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
    expect($slugs)->not->toBe([]);

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

// The corpus carries its own wording rather than a lang group: 398 entries
// across 33 jurisdictions would owe a line in all twenty-six shipped locales,
// and twenty-four of those files would be English pasted into another language's
// file. English is required because it is the fallback every other reader lands
// on; Dutch because it is the second locale the product ships.
it('carries English and Dutch wording for every bundled corpus entry', function (): void {
    $corpora = seededWordingCorpora();
    expect($corpora)->not->toBe([]);

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

    expect($entries)->toBeGreaterThan(300);
    expect($gaps)->toBe([], "corpus entries a reader outside the jurisdiction cannot read:\n  ".implode("\n  ", $gaps));
});
