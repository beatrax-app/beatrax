<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/features/sync/merge-registry-authoring.md#primary-keys-stay-out--with-one-exception
 */

// Five comments in this one file said a table's `id` was DERIVED when the writer
// mints it. The premise is wrong and the advice that follows it is right — a
// derived id and a `random_int` both sort in no useful order — so nothing ever
// reads the sentence in front of the correct paging rule.

// Where the difference bites is a tie-break: a derived id is a fold of values
// BOTH devices compute, so it is identical on both and is the one kind an
// ORDER BY may rest on. A minted or autoincrement id is not.
const DERIVED_CLAIM_REGISTRY = 'Modules/Sync/Internal/Config/MergeRulesRegistry.php';

// A comment block, then the table key it introduces.
const DERIVED_CLAIM_BLOCK = '/((?:^[ \t]*\/\/[^\n]*\n)+)[ \t]*\'([a-z_]+)\'\s*=>\s*\[/m';

// Only a claim about the row's OWN id counts. `notifications` says a FIELD is
// locally derived and that is a different sentence; a block that says MINTED is
// making the opposite claim and is not read as one.
const DERIVED_CLAIM_SENTENCE = '/`id`[^\n]{0,40}\bis\b[^\n]{0,20}deriv/i';

const DERIVED_CLAIM_DISCLAIMER = '/\bMINTED\b/';

/**
 * Every table a production writer really derives an id for, read from the call
 * sites rather than from a list anybody keeps by hand.
 *
 * @return list<string>
 */
function derivedClaimProductionTables(): array
{
    $tables = [];

    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))) as $entry) {
        $path = $entry->getPathname();

        if (! $entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }

        // A migration that derived a column once does not make it derived, and a
        // test naming the helper is not a writer. `anomaly_alerts` is exactly
        // that trap: one migration, and the live writer mints.
        if (str_contains($path, '/tests/') || str_contains($path, '/Database/')) {
            continue;
        }

        foreach (PatternScan::sets("/DerivedRowId::for\('([a-z_]+)'/", (string) file_get_contents($path)) as $set) {
            $tables[] = $set[1];
        }
    }

    $tables = array_values(array_unique($tables));
    sort($tables);

    return $tables;
}

/**
 * @return list<array{table:string,line:int}>
 */
function derivedClaimsInTheRegistry(): array
{
    $source = (string) file_get_contents(base_path(DERIVED_CLAIM_REGISTRY));
    $claims = [];

    foreach (PatternScan::setsWithOffsets(DERIVED_CLAIM_BLOCK, $source) as $set) {
        $block = $set[1][0];

        if (PatternScan::matches(DERIVED_CLAIM_DISCLAIMER, $block)) {
            continue;
        }

        if (PatternScan::matches(DERIVED_CLAIM_SENTENCE, $block)) {
            $claims[] = [
                'table' => $set[2][0],
                'line' => substr_count(substr($source, 0, (int) $set[0][1]), "\n") + 1,
            ];
        }
    }

    return $claims;
}

it('claims a derived id only where a writer derives one', function (): void {
    $derives = derivedClaimProductionTables();
    $claims = derivedClaimsInTheRegistry();
    $offenders = [];

    foreach ($claims as $claim) {
        if (! in_array($claim['table'], $derives, true)) {
            $offenders[] = sprintf('%s:%d says `%s` derives its id', DERIVED_CLAIM_REGISTRY, $claim['line'], $claim['table']);
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A merge-rule comment says a table derives its `id`, and no production writer calls',
        'DerivedRowId::for() for it — so it is minted or counted, and two devices do not agree',
        'on it. Read the writer: a migration that derived the column once does not make it',
        'derived. Say MINTED, and keep whatever the block says about what makes two devices',
        'one row — that is usually a UNIQUE, and usually still true:',
        ...$offenders,
    ]));

    // Both halves read something. A claim set that empties says the comments
    // stopped being recognised, not that they all became true.
    expect($claims)->not->toBeEmpty()
        ->and($derives)->not->toBeEmpty();
});

it('reads a derived claim, a minted disclaimer, and a sentence about a field', function (): void {
    $claim = PatternScan::matches(DERIVED_CLAIM_SENTENCE, '// The `id` is DERIVED, from (user_id, direction,');
    $minted = PatternScan::matches(DERIVED_CLAIM_DISCLAIMER, '// The `id` is MINTED by ChainLinkInsertHelper, not derived.');
    $field = PatternScan::matches(DERIVED_CLAIM_SENTENCE, '// `state` is deliberately absent — it is locally derived, never');

    // The third is why the rule reads `id` and not `deriv`: the file talks about
    // deriving fields, digests and cluster keys, and none of those is this.
    expect($claim)->toBeTrue()
        ->and($minted)->toBeTrue()
        ->and($field)->toBeFalse();
});
