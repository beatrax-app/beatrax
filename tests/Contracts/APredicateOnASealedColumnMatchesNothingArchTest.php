<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Tests\Contracts\Support\BackendSourceFiles;

// Comparing a sealed column to a plaintext value matches nothing for an
// enrolled reader and everything it used to for an unenrolled one — one
// statement, two answers, neither an error. knowinglyPlaintext() is the other
// half of the rule; this guard makes its argument load-bearing.

/**
 * @link ../../.docs/features/sync/sensitive-columns-at-rest.md
 */

// The six demo seeders this guard shipped with an allow-list for now match on
// the plaintext source_ref tag DemoTransactionRef mints, so the rule holds over
// the whole tree and the list, and the case that kept it true, are gone with
// them. The control at the foot of this file replaces what they proved.

// Two of the twelve sealed names also sit on a table that does NOT seal them:
// `description` on three staging and audit tables, `iban` on accounts. A bare
// name cannot tell those apart, so for these two the guard reads the table the
// statement names and asks only about the sealing one.
const SEALED_NAMES_SHARED_WITH_A_PLAINTEXT_TABLE = [
    'description' => 'transactions',
    'iban' => 'counterparties',
];

/**
 * The table the fluent statement containing $index reads, or null when it names
 * none — an Eloquent model, or a builder handed in from elsewhere.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function tableNamedBeforeToken(array $tokens, int $index, string $path): ?string
{
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];

        // A statement boundary: whatever named a table before this point
        // belongs to a different query than the predicate being judged.
        if (is_string($token) && in_array($token, [';', '{', '}'], true)) {
            return null;
        }

        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        if (in_array($token[1], ['table', 'from'], true)
            && preg_match("/^\\s*['\"]([a-z_]+)['\"]/", BackendSourceFiles::callArguments($tokens, $cursor), $match) === 1) {
            return $match[1];
        }

        // Eloquent names its table nowhere in the statement, so the model is
        // asked for it rather than a short name being mapped here by hand — a
        // hand-written map is a second copy of $table that goes stale quietly.
        if (isset($tokens[$cursor + 1]) && is_array($tokens[$cursor + 1]) && $tokens[$cursor + 1][0] === T_DOUBLE_COLON) {
            $table = tableOfModelNamed($token[1], $path);

            if ($table !== null) {
                return $table;
            }
        }
    }

    return null;
}

/**
 * The table an Eloquent model declares, resolved through the importing file's
 * own use statements, or null when the short name is not an imported model.
 */
function tableOfModelNamed(string $shortName, string $path): ?string
{
    if (preg_match('/^use\\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($shortName, '/').');/m', (string) file_get_contents($path), $match) !== 1) {
        return null;
    }

    if (! class_exists($match[1]) || ! is_subclass_of($match[1], Model::class)) {
        return null;
    }

    /** @var Model $model */
    $model = new $match[1];

    return $model->getTable();
}

/**
 * @return list<string> the bare column names the registry seals, deduplicated
 */
function sealedColumnNames(): array
{
    $names = [];

    foreach (SensitiveFieldRegistry::columns() as $qualified) {
        $parts = explode('.', $qualified, 2);

        if (count($parts) === 2 && $parts[1] !== '') {
            $names[$parts[1]] = true;
        }
    }

    return array_keys($names);
}

// Token-based, because BackendSourceFiles::codeTokens() drops comments: a guard
// that reads its own prose as code refuses the sentence explaining why the call
// beneath it was avoided.
/**
 * @param  list<string>  $columns
 * @return list<string>
 */
function sealedPredicatesIn(string $path, array $columns): array
{
    return sealedPredicatesInSource($path, (string) file_get_contents($path), $columns);
}

/**
 * The same reading over source held in hand, so the control below can plant a
 * subject instead of nominating a file the rule would then have to excuse.
 *
 * @param  list<string>  $columns
 * @return list<string>
 */
function sealedPredicatesInSource(string $path, string $source, array $columns): array
{
    $tokens = BackendSourceFiles::tokensOf($path, $source);
    $found = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['where', 'orWhere', 'whereIn'], true)) {
            continue;
        }

        $arguments = BackendSourceFiles::callArguments($tokens, $index);

        // A closure handed to where() groups other predicates rather than being
        // one — Laravel wraps it in parentheses. Its own where* calls are
        // tokens in this same walk and get judged on their own terms, so
        // reading the body here would attribute them to the wrong verb.
        if (preg_match('/^\s*(static\s+)?(function|fn)\b/', $arguments) === 1) {
            continue;
        }

        foreach ($columns as $column) {
            if (! str_contains($arguments, "'".$column."'") && ! str_contains($arguments, '"'.$column.'"')) {
                continue;
            }

            // One argument is a column being NAMED, not compared — whereNull
            // and whereNotNull read a sealed column perfectly well, because a
            // sealed value is still a value.
            if (! str_contains($arguments, ',')) {
                continue;
            }

            $sealingTable = SEALED_NAMES_SHARED_WITH_A_PLAINTEXT_TABLE[$column] ?? null;

            // Only for a shared name, and only to clear a statement that names
            // a DIFFERENT table. Saying nothing is not a clearance — an
            // Eloquent model seals the same column and names no table here —
            // so an unnamed table stays reported.
            $namedTable = $sealingTable === null ? null : tableNamedBeforeToken($tokens, $index, $path);

            if ($sealingTable !== null && $namedTable !== null && $namedTable !== $sealingTable) {
                continue;
            }

            $found[] = $token[1].'('.$column.')';
        }
    }

    return array_values(array_unique($found));
}

it('compares no sealed column against a value anywhere in the tree', function (): void {
    $columns = sealedColumnNames();
    expect($columns)->not->toBe([], 'The registry named no sealed columns, so a clean answer below is this guard reading nothing.');

    $files = BackendSourceFiles::all();
    expect(count($files))->toBeGreaterThan(500, 'The walk opened almost no PHP, so a clean answer below is the walk being broken.');

    $offenders = [];

    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $hits = sealedPredicatesIn($path, $columns);

        if ($hits === []) {
            continue;
        }

        $offenders[] = $relative.'  '.implode(' ', $hits);
    }

    expect($offenders)->toBe([], implode("\n", [
        'A sealed column holds ciphertext with a fresh nonce per write, so comparing one',
        'to a value matches nothing for a reader who has enabled encryption — silently,',
        'and only for them. Predicate on a column SensitiveFieldRegistry leaves plaintext,',
        'or filter in PHP after decrypting. These compare a sealed one:',
        ...$offenders,
    ]));
});

// Counting the files proves the walk opened the tree; this proves the matcher
// inside it still recognises the shape, which a clean tree cannot. Planted
// source and not a nominated file: a file kept in the tree to be found would
// have to be excused from the very rule it is the evidence for.
it('still recognises a sealed predicate, and still leaves a column merely named alone', function (): void {
    $columns = sealedColumnNames();
    $path = base_path('Modules/PlantedByTheControl.php');

    $compared = <<<'PHP'
        <?php
        $db->table('transactions')->where('user_id', 1)->where('description', 'Bol.com via PayPal')->first();
        PHP;

    $named = <<<'PHP'
        <?php
        $db->table('transactions')->where('user_id', 1)->whereNotNull('description')->get();
        PHP;

    expect(sealedPredicatesInSource($path, $compared, $columns))
        ->toBe(['where(description)'], 'The scan no longer reports a sealed column compared to a value, so a clean tree above says nothing.')
        ->and(sealedPredicatesInSource($path, $named, $columns))
        ->toBe([], 'The scan reports a column it only NAMES, so what it found above need not have been a comparison.');
});
