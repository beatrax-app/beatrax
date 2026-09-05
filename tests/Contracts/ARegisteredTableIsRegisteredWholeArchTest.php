<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;

// Both misses the last at-rest pass found have one shape, and it is not that
// nobody looked. It is one column of a pair recorded and the other not, on the
// same table: detected_name yes / display_name_override no, and baseline_value
// argued on the page but absent from the register.
const REGISTERED_WHOLE_CONTENT_TOKENS = [
    'name', 'note', 'description', 'subject', 'label', 'email', 'iban',
    'pattern', 'sender', 'payee', 'memo', 'title', 'body', 'address',
    'filename', 'file_name', 'value',
];

// Content-shaped by name and not content by nature. Each is here with the same
// burden the register itself carries: a reason, so that "absent from the three
// lists" reads as a decision rather than as a column nobody classified.
const REGISTERED_WHOLE_STRUCTURAL = [
    'migration_import_baseline.field_name' => 'Holds the NAME of the column the baseline was taken for — the string "description", not a description. It is also the merge key the resolver looks a baseline up by.',
    'transactions.normalization_version' => 'An integer version stamp for the normaliser that produced counterparty_normalized; it matches the token only through "normalization".',
    'transactions.value_date' => 'A date, not a value: the day the bank applied the entry. It matches on the "value" token alone.',
];

// Content, assessed, and NOT yet sealed — kept out of knowinglyPlaintext()
// deliberately, because that list means "AEAD cannot apply here" and for these
// it can. Naming them in the guard that found them is what stops the next pass
// re-deriving the same three columns from scratch.
const REGISTERED_WHOLE_OPEN = [
    'categories.name' => 'categories.slug is Str::slug() of it, carries unique(user_id, slug) plus a partial UNIQUE over the global rows, and cannot be sealed — so a sealed name leaves the readable copy one column over. The global rows additionally have user_id IS NULL while the codec keys on a user. Same shape as accounts.name, which is on the register with the same argument.',
    'merchant_aliases.friendly_name' => 'A user\'s own naming of a merchant. The only column here with no blocker at all: no predicate, no UNIQUE, no derivation from an unsealable neighbour, and its writers are Livewire components, so the app-lock key is in scope. Sealing it is a read-path change across the alias screens and the YAML importer.',
    'migration_runs.original_filename' => 'The browser-supplied upload name, stored verbatim and copied forward onto every reconcile run. No index, no unique, no predicate, never rendered from the column — and its writer is a Livewire component, so the app-lock key is in scope. Its sibling file_imports.source_filename holds the same kind of string and is on the register, because that one is written from a queue.',
    'known_counterparty_ibans.notes' => 'A free-text note beside the unsealable real_iban. Nullable, and no production writer fills it today, so the column is empty on every install — which is why it is open work rather than a live leak.',
];

/**
 * @return list<string>
 */
function registeredWholeTables(): array
{
    $pairs = [
        ...SensitiveFieldRegistry::columns(),
        ...array_keys(SensitiveFieldRegistry::knowinglyPlaintext()),
        ...array_keys(SensitiveFieldRegistry::blindIndexColumns()),
    ];

    $tables = [];
    foreach ($pairs as $pair) {
        $tables[explode('.', $pair, 2)[0]] = true;
    }

    ksort($tables);

    return array_keys($tables);
}

/**
 * @return list<string> every `{table}.{column}` the three lists between them classify
 */
function registeredWholeClassified(): array
{
    return [
        ...SensitiveFieldRegistry::columns(),
        ...array_keys(SensitiveFieldRegistry::knowinglyPlaintext()),
        ...array_keys(SensitiveFieldRegistry::blindIndexColumns()),
    ];
}

function registeredWholeIsContentShaped(string $column): bool
{
    foreach (REGISTERED_WHOLE_CONTENT_TOKENS as $token) {
        if (str_contains($column, $token)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function registeredWholeColumnsOf(string $table): array
{
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();

    if (! $schema->hasTable($table)) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (array $column): string => is_string($column['name']) ? $column['name'] : '',
        $schema->getColumns($table),
    ), static fn (string $name): bool => $name !== ''));
}

it('classifies every content-shaped column of a table the registry has already started classifying', function (): void {
    $classified = registeredWholeClassified();
    $tables = registeredWholeTables();

    expect($tables)->not->toBeEmpty();

    $unclassified = [];
    foreach ($tables as $table) {
        foreach (registeredWholeColumnsOf($table) as $column) {
            $pair = $table.'.'.$column;
            if (! registeredWholeIsContentShaped($column)) {
                continue;
            }
            $accounted = in_array($pair, $classified, true)
                || array_key_exists($pair, REGISTERED_WHOLE_STRUCTURAL)
                || array_key_exists($pair, REGISTERED_WHOLE_OPEN);
            if ($accounted) {
                continue;
            }
            $unclassified[] = $pair;
        }
    }

    expect($unclassified)->toBe([], implode("\n", [
        'A table with a column in the registry owns its whole surface. These are content-shaped',
        'and named by none of columns(), knowinglyPlaintext(), blindIndexColumns() or the',
        'structural or open map in this file: '.implode(', ', $unclassified),
    ]));
});

// The structural map is the escape hatch, so it gets the same two checks the
// registry's own allowlist gets: it may not name a column the registry has
// since classified, and it may not name a column that does not exist.
it('keeps the structural map disjoint from the three registry lists', function (): void {
    $overlap = array_values(array_intersect(
        array_keys(REGISTERED_WHOLE_STRUCTURAL),
        registeredWholeClassified(),
    ));

    expect($overlap)->toBe([], 'a column cannot be both structural and classified: '.implode(', ', $overlap));
});

it('names no structural column that the schema does not have', function (): void {
    $missing = [];
    foreach (array_keys(REGISTERED_WHOLE_STRUCTURAL) as $pair) {
        [$table, $column] = explode('.', $pair, 2);
        if (! in_array($column, registeredWholeColumnsOf($table), true)) {
            $missing[] = $pair;
        }
    }

    expect($missing)->toBe([], 'structural entries naming a column that is gone: '.implode(', ', $missing));
});

// The token list is the guard's floor and its weak point: it catches payee_name
// and misses who_it_went_to. This pins that it is at least sharp enough to see
// the pair whose split is the reason the guard exists.
it('sees both halves of the pair that motivated it', function (): void {
    expect(registeredWholeIsContentShaped('detected_name'))->toBeTrue()
        ->and(registeredWholeIsContentShaped('display_name_override'))->toBeTrue()
        ->and(registeredWholeIsContentShaped('baseline_value'))->toBeTrue();
});

// An open entry that the registry has since classified is a stale exemption,
// and a stale exemption is how a column drops out of both lists at once.
it('holds the open map to the same two checks as the structural one', function (): void {
    $classified = registeredWholeClassified();

    $settled = array_values(array_intersect(array_keys(REGISTERED_WHOLE_OPEN), $classified));
    expect($settled)->toBe([], 'these are classified now, so the open entry must go: '.implode(', ', $settled));

    $missing = [];
    foreach (array_keys(REGISTERED_WHOLE_OPEN) as $pair) {
        [$table, $column] = explode('.', $pair, 2);
        if (! in_array($column, registeredWholeColumnsOf($table), true)) {
            $missing[] = $pair;
        }
    }

    expect($missing)->toBe([], 'open entries naming a column that is gone: '.implode(', ', $missing));

    $overlap = array_values(array_intersect(
        array_keys(REGISTERED_WHOLE_OPEN),
        array_keys(REGISTERED_WHOLE_STRUCTURAL),
    ));
    expect($overlap)->toBe([], 'a column cannot be both structural and open: '.implode(', ', $overlap));
});
