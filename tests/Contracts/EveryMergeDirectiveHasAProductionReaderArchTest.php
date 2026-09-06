<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// `_delete_wins` was declared for every covered table, set deliberately to
// false on the one table whose rows carry a ledger, read by a public accessor,
// and consulted by nothing that ships. The merge compared a tombstone against
// the newest edit with `>= 0` for every table, so the rule was a constant
// wearing a configuration key. A unit test proved the accessor; nothing proved
// the merge asked it.
//
// The registry's directives are the underscore-prefixed keys inside a table's
// rule block. Each has to be readable from a production file, or it is a
// setting a contributor will keep tuning and nothing will keep honouring.

const MERGE_DIRECTIVE_REGISTRY = 'Modules/Sync/Internal/Config/MergeRulesRegistry.php';

// The accessor that exposes each directive, so the walk looks for a real
// caller rather than for the raw key — production reads `deleteWins()`, never
// `'_delete_wins'`. Adding a directive without an entry here fails below.
const MERGE_DIRECTIVE_ACCESSORS = [
    '_delete_wins' => 'deleteWins',
    '_create_required' => 'requiredCreateColumns',
];

/** @return list<string> every underscore-prefixed directive the registry declares */
function mergeDirectivesDeclared(): array
{
    $source = (string) file_get_contents(base_path(MERGE_DIRECTIVE_REGISTRY));

    $found = array_map(
        static fn (array $set): string => $set[1],
        PatternScan::sets("/'(_[a-z_]+)'\s*=>/", $source),
    );

    $unique = array_values(array_unique($found));
    sort($unique);

    return $unique;
}

/** @return list<string> production files that call the named accessor */
function mergeDirectiveCallers(string $accessor): array
{
    $callers = [];
    $root = base_path().'/';

    foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
        $relative = str_replace($root, '', $path);

        if ($relative === MERGE_DIRECTIVE_REGISTRY) {
            continue;
        }

        if (PatternScan::matches('/->'.preg_quote($accessor, '/').'\(/', (string) file_get_contents($path))) {
            $callers[] = $relative;
        }
    }

    sort($callers);

    return $callers;
}

it('gives every merge directive a reader in the code that ships', function (): void {
    $declared = mergeDirectivesDeclared();

    // The denominator. A registry that stopped declaring directives, or a
    // reader that stopped finding them, would report every one honoured.
    expect($declared)->not->toBe([], 'no directive was read out of the registry, so this rule has no subject')
        ->and($declared)->toContain('_delete_wins');

    $unread = [];

    foreach ($declared as $directive) {
        $accessor = MERGE_DIRECTIVE_ACCESSORS[$directive] ?? null;

        if ($accessor === null) {
            $unread[] = $directive.' has no accessor named in this rule, so nothing here can check it is honoured';

            continue;
        }

        $callers = mergeDirectiveCallers($accessor);

        if ($callers === []) {
            $unread[] = $directive.' is declared per table and '.$accessor.'() is called by nothing that ships';
        }
    }

    expect($unread)->toBe([], implode("\n  ", [
        'A merge directive is a per-table decision about what happens to a reader\'s row. One that no',
        'shipping code reads is a constant wearing a configuration key: a contributor sets it, a unit',
        'test proves the accessor returns it, and the merge goes on doing the same thing for every',
        'table. That is how `accounts` came to lose a row to a tie it declares it should win.',
        'Offenders:',
        ...$unread,
    ]));
});

// The reader has to find a caller that exists and miss one that does not, or
// the verdict above is a walk that never opened a file.
it('reads a real caller and is not fooled by the registry naming its own key', function (): void {
    expect(mergeDirectiveCallers('deleteWins'))->not->toBe([], 'deleteWins() has no production caller, which is the defect this rule exists for')
        ->and(mergeDirectiveCallers('noSuchAccessorExistsAnywhere'))->toBe([]);

    // The registry declares the key and is excluded from the walk, so a rule
    // that counted it would pass on a directive only the registry mentions.
    expect(mergeDirectiveCallers('deleteWins'))->not->toContain(MERGE_DIRECTIVE_REGISTRY);
});
