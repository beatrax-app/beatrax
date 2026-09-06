<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\Support\ReconciledRowExemptions;
use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\UnannouncedWrites;

// The lock on a reconciled row was thirteen refusals spread across the tree and
// no statement anywhere of what was allowed through it. Each writer that skips
// the refusal now names the requirement that lets it, and this reads the tree
// back to find the ones nothing named.

// Transcribed from the requirement rather than read from the list under test.
// Two independent statements that have to agree is the whole check: a list that
// grew a sixth reason has nothing here to disagree with, and an entry citing an
// identifier the lock never granted stops being possible to write.
const RECONCILED_ROW_MANDATED_EXEMPTIONS = ['B1-R15', 'B4-R23', 'B5-R16', 'B8-R11', 'B8-R8'];

const RECONCILED_ROW_IDENTIFIER = '/^[A-Z]{1,4}[0-9]{0,2}-R[0-9]+$/';

// What the reader matched against a bank statement, and therefore what the
// assertion is about. A derived column is not on it: re-deriving a fingerprint,
// re-keying a blind index or stamping provenance restates a value the row
// already holds, and none of them is a second opinion about the money.
const RECONCILED_ROW_ASSERTED_COLUMNS = [
    'account_id', 'amount_minor', 'booked_at', 'category_id', 'counterparty_iban',
    'counterparty_id', 'counterparty_name', 'currency', 'description', 'fx_rate_used',
    'note', 'pair_transaction_id', 'posted_at', 'settled_amount_minor',
    'settled_currency', 'status', 'type', 'value_date',
];

const RECONCILED_ROW_LOCK_CONSULTED = '/TransactionStatusQuery|locksEdits|isReconciled|reconciledIdsAmong/';

// The subject is the row a caller NAMES, which is what the lock is a rule
// about. A sweep narrowing by anything else — an import run, a parent id, a
// chunk it derived for itself — is acting on the table rather than on one
// reader's assertion, and refusing it there would strand the row instead.
/** @return array<string, string> shape name => the pattern that reads it */
function reconciledRowWriteShapes(): array
{
    $anchor = '(?:->table\(\'transactions\'\)|Transaction::(?:query\(\)|where))';
    $named = '->where\(\'(?:transactions\.)?id\',';
    $columns = '(?:'.implode('|', RECONCILED_ROW_ASSERTED_COLUMNS).')';

    return [
        'builder delete' => '/'.$anchor.'[^;]*'.$named.'[^;]*->(?:delete|forceDelete)\(/',
        'builder column' => '/'.$anchor.'[^;]*'.$named.'[^;]*->update\(\s*\[[^;]*\''.$columns.'\'\s*=>/',
        'builder amount' => '/'.$anchor.'[^;]*'.$named.'[^;]*->update\([^;]*->toColumns\(\)/',
        'statement update' => '/update\s+transactions\s+set\s+[^;]*\b'.$columns.'\s*=[^;]*\bwhere\s+id\s*(?:=|in\b)/i',
        'statement delete' => '/delete\s+from\s+transactions[^;]*\bwhere\s+id\s*(?:=|in\b)/i',
        'runtime table' => '/->table\(\$[A-Za-z_][A-Za-z0-9_]*\)[^;]*'.$named.'[^;]*->(?:update|delete)\(/',
    ];
}

/** @return list<string> the shapes the source carries, in the order they are read */
function reconciledRowShapesIn(string $source): array
{
    $shapes = [];

    foreach (reconciledRowWriteShapes() as $name => $pattern) {
        if (PatternScan::matches($pattern, $source)) {
            $shapes[] = $name;
        }
    }

    return $shapes;
}

/** @return list<string> every production file this rule reads */
function reconciledRowScannedFiles(): array
{
    // A demo seeder writes rows it has just minted, so there is no assertion of
    // anybody's on them yet; migrations are excluded upstream for the same kind
    // of reason, and two of them are pinned exemptions in their own right.
    return array_values(array_filter(
        BackendSourceFiles::all(),
        static fn (string $path): bool => ! str_contains($path, '/Database/Seeders/'),
    ));
}

function reconciledRowCode(string $path): string
{
    return PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
}

/** @return array<string, list<string>> repo-relative writer => the shapes found in it */
function reconciledRowWriters(): array
{
    static $writers = null;

    if ($writers !== null) {
        return $writers;
    }

    $writers = [];

    foreach (reconciledRowScannedFiles() as $path) {
        $source = reconciledRowCode($path);
        $shapes = reconciledRowShapesIn($source);

        // A writer naming its table at runtime reaches whatever row an
        // operation names, so it counts only where the file knows this table
        // by name at all — otherwise every generic writer in the tree is one.
        if ($shapes === ['runtime table'] && ! str_contains($source, '\'transactions\'')) {
            continue;
        }

        if ($shapes !== []) {
            $writers[str_replace(base_path().'/', '', $path)] = $shapes;
        }
    }

    return $writers;
}

it('reads a write that names one row and leaves a sweep of the table alone', function (): void {
    $reaches = [
        'a typed column' => '$db->table(\'transactions\')->where(\'id\', $id)->where(\'user_id\', $u)->update([\'type\' => $t]);',
        'a delete' => '$db->table(\'transactions\')->where(\'id\', $id)->delete();',
        'an eloquent note' => 'Transaction::query()->where(\'id\', $id)->update([\'note\' => $n]);',
        'a money value object' => '$db->table(\'transactions\')->where(\'id\', $id)->update($amount->toColumns());',
        'a written statement' => '$db->update(\'update transactions set description = ? where id = ?\', $b);',
        'a table named at runtime' => '$db->table($table)->where(\'id\', $pk)->update([$field => $value]);',
    ];

    $leavesAlone = [
        'a re-derived fingerprint' => '$db->table(\'transactions\')->where(\'id\', $id)->update([\'fingerprint\' => $f]);',
        'a stamped provenance map' => '$db->update(\'update transactions set field_provenance = ? where id = ?\', $b);',
        'a sweep of an import run' => '$db->table(\'transactions\')->whereIn(\'import_run_id\', $runs)->delete();',
        'a cascade behind a parent' => '$db->table(\'transactions\')->whereIn(\'id\', $ids)->delete();',
        'a read' => '$db->table(\'transactions\')->where(\'id\', $id)->first();',
    ];

    foreach ($reaches as $description => $source) {
        expect(reconciledRowShapesIn($source))->not->toBe([], $description.' reaches a row the caller named and this guard has to read it as one');
    }

    foreach ($leavesAlone as $description => $source) {
        expect(reconciledRowShapesIn($source))->toBe([], $description.' is not a second opinion about one reader\'s assertion and this guard must leave it alone');
    }
});

it('names every writer that reaches a reconciled row on the exemption list', function (): void {
    $exempt = ReconciledRowExemptions::writers();
    $offenders = [];

    foreach (reconciledRowWriters() as $file => $shapes) {
        if (array_key_exists($file, $exempt) || PatternScan::matches(RECONCILED_ROW_LOCK_CONSULTED, reconciledRowCode(base_path($file)))) {
            continue;
        }

        $offenders[] = $file.' ('.implode(', ', $shapes).')';
    }

    // A walk that stopped reading answers "nothing found" in the same words a
    // clean tree does, so the denominator and one known writer are asserted
    // before the verdict is.
    expect(count(reconciledRowScannedFiles()))->toBeGreaterThan(2000)
        ->and(array_key_exists('Modules/Ledger/Public/Services/TransactionStatusWriter.php', reconciledRowWriters()))
        ->toBeTrue('The sanctioned status writer has to be found by this walk, or the walk is reading something other than the tree.');

    expect($offenders)->toBe(
        [],
        'A writer that changes a transaction the caller named either refuses the row when it is reconciled '
        .'or is named on ReconciledRowExemptions with the requirement that lets it through. A reconcile is '
        .'the reader\'s own assertion that this row and a bank statement agree; a writer nothing admitted is '
        ."the assertion being taken back by something that never asked. Offenders:\n  "
        .implode("\n  ", $offenders),
    );
});

it('grants every exemption against a requirement the lock itself mandates', function (): void {
    $declared = ReconciledRowExemptions::requirements();
    $mandated = RECONCILED_ROW_MANDATED_EXEMPTIONS;

    sort($declared);
    sort($mandated);

    expect($mandated)->not->toBe([])
        ->and($declared)->toBe(
            $mandated,
            'The exemption list has to hold exactly the reasons the lock grants, no more and no fewer. An '
            .'entry here that the requirement does not mandate is a writer somebody decided to allow; a '
            .'reason the requirement mandates and this list omits is one nothing in the tree is checked against.',
        );

    foreach (ReconciledRowExemptions::reasons() as $requirement => $reason) {
        expect(PatternScan::matches(RECONCILED_ROW_IDENTIFIER, $requirement))->toBeTrue(
            $requirement.' is not the shape of a requirement identifier, so no page defines it and nothing mandates the exemption it is standing for.',
        )->and($reason)->not->toBe('', $requirement.' has to say what it admits, or the list records that something was allowed and not why.');
    }
});

// A proof is read by nothing but this file, and it sits in a class under
// Modules/. Spelt as SQL it is indistinguishable from the statement it stands
// for, and the capture guards — which ask about the spelling rather than the
// caller — read it as a write this registry performs. The retyping pass's pin
// quoted its own statement and did exactly that once the pass moved behind
// TransactionTypeWriter and the real statement was gone.
it('keeps a proof from reading as the write it stands for', function (): void {
    $spelledAsSql = [];

    foreach (ReconciledRowExemptions::proofs() as $file => $pattern) {
        if (PatternScan::matches(UnannouncedWrites::RAW_STATEMENT, $pattern)) {
            $spelledAsSql[] = $file.' — '.$pattern;
        }
    }

    expect(ReconciledRowExemptions::proofs())->not->toBe([])
        ->and(PatternScan::matches(UnannouncedWrites::RAW_STATEMENT, '/UPDATE transactions SET type = \\?/'))
        ->toBeTrue('The reader has to recognise the pin that caused this rule, or an empty result means nothing.')
        ->and($spelledAsSql)->toBe(
            [],
            'A pin proves its writer is still the one the requirement admits, so it names the code — a '
            .'method call, a constructor argument, a schema builder line. Spelt as a SQL statement it is '
            ."a write in the tree as far as every guard that reads spellings is concerned.\n  "
            .implode("\n  ", $spelledAsSql),
        );
});

it('keeps every exempt writer standing on the write its pin was granted for', function (): void {
    $stale = [];

    foreach (ReconciledRowExemptions::proofs() as $file => $pattern) {
        $path = base_path($file);

        if (! is_file($path)) {
            $stale[] = $file.' — the file the exemption names is gone';

            continue;
        }

        if (! PatternScan::matches($pattern, (string) file_get_contents($path))) {
            $stale[] = $file.' — '.$pattern.' no longer matches';
        }
    }

    expect(ReconciledRowExemptions::proofs())->not->toBe([])
        ->and($stale)->toBe(
            [],
            'Each exemption is pinned to the write that earned it. A pin whose proof stopped matching outlived '
            ."its reason, and an exemption nobody re-read is how the next unadmitted writer arrives.\n  "
            .implode("\n  ", $stale),
        );
});
