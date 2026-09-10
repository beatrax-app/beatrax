<?php

declare(strict_types=1);

/**
 * @link ../../.docs/features/counterparties/architecture.md
 */

// counterparties holds names the reader authored, and transactions.counterparty_id
// carries no foreign key on purpose — a delete leaves the id dangling rather than
// cascading history away. So an id reaching a read is not evidence that the row it
// names belongs to the reader asking, and one read had already lost its scope:
// TaxTagQuery resolved the banner's counterparty by id alone.

const COUNTERPARTY_READ_FLOOR = 12;

// A migration walks every install's rows by definition: it runs before any reader
// is authenticated and has no user to scope to. Nothing else may be added here —
// a runtime caller always knows whose row it wants.
function counterpartyReadIsAMigration(string $path): bool
{
    return str_contains($path, '/Database/Migrations/');
}

/**
 * @return list<int> the line of each read that names no reader
 */
function counterpartyReadsWithoutAReader(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $unscoped = [];

    for ($index = 0; $index < $count; $index++) {
        if (! counterpartyTableCallAt($tokens, $index)) {
            continue;
        }

        $line = is_array($tokens[$index]) ? $tokens[$index][2] : 0;

        if (! counterpartyStatementNamesAReader($tokens, $index, $count)) {
            $unscoped[] = $line;
        }
    }

    return $unscoped;
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function counterpartyTableCallAt(array $tokens, int $index): bool
{
    $token = $tokens[$index];

    if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'table') {
        return false;
    }

    // ->table( 'counterparties' ) — the string has to be the sole argument, or
    // a call passing it as one of several is not the read this is about.
    return ($tokens[$index + 1] ?? null) === '('
        && is_array($tokens[$index + 2] ?? null)
        && $tokens[$index + 2][0] === T_CONSTANT_ENCAPSED_STRING
        && trim($tokens[$index + 2][1], "'\"") === 'counterparties'
        && ($tokens[$index + 3] ?? null) === ')';
}

// The scope may be named anywhere in the same statement, because a builder is
// chained across lines and the where() that carries it is often the last link.
/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function counterpartyStatementNamesAReader(array $tokens, int $index, int $count): bool
{
    $depth = 0;

    for ($ahead = $index; $ahead < $count; $ahead++) {
        $token = $tokens[$ahead];

        if ($token === '(') {
            $depth++;
        } elseif ($token === ')') {
            $depth--;
        } elseif ($token === ';' && $depth <= 0) {
            return false;
        } elseif (is_array($token)
            && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && trim($token[1], "'\"") === 'user_id') {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function counterpartyReadingFiles(): array
{
    $found = [];

    /** @var Iterator<string, SplFileInfo> $walk */
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($walk as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), "table('counterparties')")) {
            $found[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($found);

    return $found;
}

it('names the reader on every runtime read of a counterparty', function (): void {
    $files = counterpartyReadingFiles();

    expect(count($files))->toBeGreaterThanOrEqual(
        COUNTERPARTY_READ_FLOOR,
        'the walk opened '.count($files).' files, which is what a reader that stopped reading looks like.',
    );

    $unscoped = [];

    foreach ($files as $relative) {
        if (counterpartyReadIsAMigration($relative)) {
            continue;
        }

        foreach (counterpartyReadsWithoutAReader((string) file_get_contents(base_path($relative))) as $line) {
            $unscoped[] = $relative.':'.$line;
        }
    }

    expect($unscoped)->toBe([], implode("\n", [
        'These read a counterparty without saying whose:',
        ...$unscoped,
        '',
        'An id is not proof of ownership here: transactions.counterparty_id has',
        'no foreign key, so a deleted row leaves its id behind for a later one to',
        'take. Add ->where(\'user_id\', $userId).',
    ]));
});

it('sees an unscoped read, and the scope wherever in the statement it is named', function (): void {
    $unscoped = '<?php $db->table(\'counterparties\')->where(\'id\', $id)->first();';
    expect(counterpartyReadsWithoutAReader($unscoped))->toBe([1]);

    // Named last, after a closure whose own parentheses the walk has to survive.
    $late = '<?php $db->table(\'counterparties\')->when($x, fn ($q) => $q->where(\'id\', $id))->where(\'user_id\', $u)->get();';
    expect(counterpartyReadsWithoutAReader($late))->toBe([]);

    // A second statement's scope must not answer for the first.
    $twoStatements = '<?php $a = $db->table(\'counterparties\')->first(); $b = $db->table(\'x\')->where(\'user_id\', $u)->get();';
    expect(counterpartyReadsWithoutAReader($twoStatements))->toBe([1]);

    // A different table that merely mentions the word is not this read.
    $other = '<?php $db->table(\'counterparty_aliases\')->where(\'id\', $id)->first();';
    expect(counterpartyReadsWithoutAReader($other))->toBe([]);
});
