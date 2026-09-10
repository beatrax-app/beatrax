<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Modules\Search\Public\Support\SearchedColumns;

/**
 * @link ../../.docs/features/search/architecture.md
 */

// transaction_search_docs.search_body is derived, and nothing in SQLite
// maintains it: external-content FTS5 stores the index only, so a row rewritten
// by a path that does not call the writer stays findable by what it used to
// say and unfindable by what it now says. Three writers had that shape at once.

// The subject is every call that seals a column of one of those tables:
// SensitiveColumnCodec is the mandatory door into an at-rest-encrypted column,
// so a writer cannot reach a counterparty name, a description, a tax note or
// either of the reader's own notes without passing through here — not even one
// that names its column through an enum rather than a literal.
const SEARCHED_SEAL_METHODS = ['encryptAttrs', 'encryptValue'];

// Keyed by path, carrying WHY the write leaves the index describing the row
// correctly. A file that starts reaching the writer stops matching its entry
// and fails, so the list cannot rot into a blanket exemption.
const SEARCHED_SEAL_ALLOWED = [
    'Modules/Ledger/Public/Actions/RecordTransactions.php' => 'Inserts rather than updates, and dispatches TransactionImported after the chunk commits; Search listens for it through IndexTransactionOnImport.',
    'Modules/Sync/Public/Casts/EncryptedJsonCast.php' => 'Seals transactions.raw_payload, the source row as the adapter read it, which no search reads.',
    'Modules/Sync/Internal/Merge/OpLogValueProjector.php' => 'Projects a peer op onto whichever column it names; OpLogReplayer refreshes every touched document through SearchIndexRefresher once the replay commits, which reaches a column of this table only while SearchDocumentRows names the table it lives on.',
    'Modules/Core/Internal/Encryption/PlaintextResidueSweep.php' => 'Re-seals a value already stored, so the plaintext the body holds is the same before and after and the document still describes the row.',
];

/**
 * @return list<array{line: int, table: string}>
 */
function searchedSealScan(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $hits = [];

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];

        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], SEARCHED_SEAL_METHODS, true)) {
            continue;
        }

        $before = searchedSealSkipSpace($tokens, $count, $index - 1, -1);
        $arrow = $tokens[$before] ?? null;

        if (! is_array($arrow) || ! in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }

        $open = searchedSealSkipSpace($tokens, $count, $index + 1, 1);

        if (($tokens[$open] ?? null) !== '(') {
            continue;
        }

        $table = searchedSealTableArgument($tokens, $count, $open);

        if ($table === null) {
            continue;
        }

        $hits[] = ['line' => $token[2], 'table' => $table];
    }

    return $hits;
}

// The first argument is the table. A literal answers for itself; anything else
// — a variable, a class constant — is reported as `?`, because a scanner that
// cannot read the table must not conclude the write is somewhere else.
/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function searchedSealTableArgument(array $tokens, int $count, int $open): ?string
{
    $argument = searchedSealSkipSpace($tokens, $count, $open + 1, 1);
    $literal = $tokens[$argument] ?? null;

    if (is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING) {
        $named = trim($literal[1], "'\"");

        return in_array($named, SearchedColumns::tables(), true) ? $named : null;
    }

    return '?';
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function searchedSealSkipSpace(array $tokens, int $count, int $from, int $step): int
{
    $at = $from;

    while ($at >= 0 && $at < $count && is_array($tokens[$at]) && $tokens[$at][0] === T_WHITESPACE) {
        $at += $step;
    }

    return $at;
}

/**
 * @return list<string>
 */
function searchedSealSourceFiles(): array
{
    $files = [];

    foreach (['Modules', 'app'] as $root) {
        $files = array_merge($files, searchedSealWalk(base_path($root)));
    }

    sort($files);

    return $files;
}

// Symlinks are skipped because mobile-app/ is a second Composer root pointing
// back here. Migrations and Seeders are skipped because a data migration seals
// a column it has just read and rewrites nothing a reader is searching yet;
// tests, because a double is not a writer.
/**
 * @return list<string>
 */
function searchedSealWalk(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            if (! in_array($entry, ['Migrations', 'Seeders', 'tests'], true)) {
                $files = array_merge($files, searchedSealWalk($path));
            }

            continue;
        }

        if (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * @return array<string, list<int>>
 */
function searchedSealSitesByFile(): array
{
    static $memo = null;

    if (is_array($memo)) {
        return $memo;
    }

    $sites = [];

    foreach (searchedSealSourceFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (searchedSealScan(BladePhpSource::forPath($path, (string) file_get_contents($path))) as $hit) {
            $sites[$relative][] = $hit['line'];
        }
    }

    return $memo = $sites;
}

it('sees a seal of a searched table and ignores one of another', function (): void {
    $source = <<<'PHP'
    <?php
    $a = $this->codec->encryptAttrs('transactions', $attrs, $userId, $session);
    $b = $this->codec->encryptValue('counterparties', 'display_name', $v, $userId, $session);
    $c = $this->codec->encryptValue($table, $column, $v, $userId, $session);
    public function encryptAttrs(string $table, array $attrs): array {}
    PHP;

    expect(array_column(searchedSealScan($source), 'table'))->toBe(['transactions', '?']);
});

// Every verdict below is read off one walk, and a walk that opened nothing
// answers "no unrefreshed write" in the same words a correct tree does.
it('walks the tree it is about to read its verdict off', function (): void {
    expect(count(searchedSealSourceFiles()))->toBeGreaterThan(
        2_000,
        'The walk read almost none of Modules/ and app/, so the verdict below is about a tree nobody opened.',
    );

    expect(count(searchedSealSitesByFile()))->toBeGreaterThan(
        4,
        'The scanner found almost no seal of a searched table. There are several; a count this low '
        .'means the token walk stopped rather than that the tree stopped sealing.',
    );
});

it('refreshes the search index wherever a searched column is sealed', function (): void {
    $offenders = [];

    foreach (searchedSealSitesByFile() as $relative => $lines) {
        if (array_key_exists($relative, SEARCHED_SEAL_ALLOWED)) {
            continue;
        }

        $source = (string) file_get_contents(base_path($relative));

        if (str_contains($source, 'SearchIndexWriterContract')) {
            continue;
        }

        $offenders[] = $relative.':'.implode(',', $lines);
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'These seal a column transaction_search_docs.search_body is composed from — '.implode(', ', SearchedColumns::tables()).' —',
        'and never reach SearchIndexWriterContract. Nothing in SQLite maintains that index: the row keeps',
        'answering to the words it used to carry and cannot be found by the ones it now does. Call',
        'upsertForTransaction() inside the same transaction as the write, or, where the sealed column is',
        'not one the body reads, add the file to SEARCHED_SEAL_ALLOWED with the reason:',
        ...$offenders,
    ]));
});

it('carries no allow-list entry that has stopped matching', function (): void {
    $sites = searchedSealSitesByFile();
    $stale = [];

    foreach (SEARCHED_SEAL_ALLOWED as $relative => $why) {
        if (! array_key_exists($relative, $sites)) {
            $stale[] = $relative.' (no longer seals a searched table)';

            continue;
        }

        if (strlen($why) < 40) {
            $stale[] = $relative.' (the reason is too thin to act on)';
        }
    }

    sort($stale);

    expect($stale)->toBe([], implode("\n  ", [
        'An entry is a claim under review. One naming a file that no longer seals a searched table excuses',
        'something that is not there, and one nobody can act on is a waiver. Delete it, or say what keeps',
        'the index describing the row:',
        ...$stale,
    ]));
});
