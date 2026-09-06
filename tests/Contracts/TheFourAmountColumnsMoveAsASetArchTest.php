<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\MoneySourceShape;

// A transaction's amount lives in four columns, and they are one fact: the
// native pair the fingerprint is composed over, the settled pair every balance,
// budget, forecast and report sums, and the rate relating them. Written apart,
// a corrected amount reached nothing (settled stayed at the old figure and the
// account balance moved by zero), and a promoted converted row landed as a
// −$30.00 expense whose settled leg read +€27.23 under no rate at all.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#money-that-left-its-seam

const AMOUNT_COLUMN_SET = ['amount_minor', 'settled_amount_minor', 'settled_currency', 'fx_rate_used'];

const AMOUNT_COLUMN_SEAM = 'Modules/Ledger/Public/ValueObjects/TransactionAmount.php';

// Each entry names a file that assembles two or more of the four under keys of
// its own, and why that is not the transactions payload this guard is about.
// The `proves` pattern re-checks the reason: when it stops matching, the
// exemption has outlived what earned it and this fails rather than waving it on.
const AMOUNT_COLUMN_PINS = [
    'Modules/Ledger/Models/Transaction.php' => [
        'reason' => 'the Eloquent cast map: it declares the columns types, and writes none of them',
        'proves' => '/function casts\(\)/',
    ],
    'Modules/Ledger/Public/Actions/SaveTransactionSplit.php' => [
        'reason' => 'a split leg carries the settled pair alone — there is no native leg beside it to relate, and no rate column on transaction_splits',
        'proves' => "/table\('transaction_splits'\)/",
    ],
    'Modules/Migration/Internal/Pipeline/StagingWriter.php' => [
        'reason' => 'migration_staging_transactions holds the source own pair and has no rate column; the pair is related on the way out of staging, by CanonicalTransaction::toAttributes()',
        'proves' => "/table\('migration_staging_transactions'\)/",
    ],
    'Modules/Recurring/Public/Services/RecurringOccurrenceQuery.php' => [
        'reason' => 'a chart point, not a row: it nulls the settled leg when it equals the native one so the shadow line is drawn only where there was a conversion',
        'proves' => '/RecurringSeriesAmountTrendDto/',
    ],
];

it('assembles the four amount columns in the one place that keeps them in step', function (): void {
    $offenders = [];
    $counted = 0;
    $pinned = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $tokens = BackendSourceFiles::codeTokens($path);

        foreach (MoneySourceShape::keyedArrayLiterals($tokens) as $line => $keys) {
            $named = array_values(array_intersect(AMOUNT_COLUMN_SET, $keys));
            if (count($named) < 2) {
                continue;
            }

            $counted++;

            if ($relative === AMOUNT_COLUMN_SEAM) {
                continue;
            }

            if (array_key_exists($relative, AMOUNT_COLUMN_PINS)) {
                $pinned[$relative] = true;

                continue;
            }

            $offenders[] = $relative.':'.$line.' names '.implode(', ', $named);
        }
    }

    // The denominator, read before the verdict below it. A tokeniser that
    // stopped resolving keyed array literals reports the same empty offender
    // list a tree that assembles the four columns in one place reports.
    expect($counted)->toBeGreaterThan(5, 'almost no array literal was read as naming two of the four amount columns — the token walk is broken, not the tree.');

    expect($offenders)->toBe([], implode("\n  ", [
        'The native pair, the settled pair and the rate between them are one fact.',
        'Build them with TransactionAmount::relate() and write them with toColumns(),',
        'which gives the pair one sign and derives the rate from the pair beside it.',
        'Spelling the keys out here puts each caller back in charge of both. Offenders:',
        ...$offenders,
    ]));

    // A pin nobody reaches any more is a claim about the tree that stopped
    // being true, and it would otherwise sit here forever.
    expect(array_keys($pinned))->toBe(array_keys(AMOUNT_COLUMN_PINS), implode("\n  ", [
        'A pinned exemption no longer excuses anything, or a pin was reached that is not declared here.',
        'The list is compared in both directions on purpose: an entry that stopped being needed reads',
        'as considered by every reader after it, and is exactly how a pin outlives the reason it was',
        'granted for. Reached: '.implode(', ', array_keys($pinned)),
    ]));
});

it('still holds each pinned exemption to the reason it was granted for', function (): void {
    foreach (AMOUNT_COLUMN_PINS as $relative => $pin) {
        expect(is_file(base_path($relative)))->toBeTrue($relative.' is pinned here and no longer exists — remove the entry or repoint it.');

        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});

/**
 * @return list<string> `line names a, b` for every literal naming two or more
 *                      of the four, driving the same reader the real walk drives
 */
function amountColumnSetsNamedIn(string $path): array
{
    $found = [];

    foreach (MoneySourceShape::keyedArrayLiterals(BackendSourceFiles::codeTokens($path)) as $line => $keys) {
        $named = array_values(array_intersect(AMOUNT_COLUMN_SET, $keys));

        if (count($named) >= 2) {
            $found[] = $line.' names '.implode(', ', $named);
        }
    }

    return $found;
}

it('sees a payload assembling the four columns by hand, and lets one leg alone', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'amount-set').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedAmountWrites
        {
            public function store(): void
            {
                $this->db->table('transactions')->insert([
                    'amount_minor' => -3000,
                    'settled_amount_minor' => 2723,
                    'settled_currency' => 'EUR',
                    'fx_rate_used' => 0.9077,
                ]);

                $this->db->table('transactions')->insert([
                    'amount_minor' => -3000,
                    'note' => 'one leg is not the set',
                ]);
            }
        }
        PHP);

    try {
        $found = amountColumnSetsNamedIn($planted);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(1, 'The reader must flag the payload spelling all four out and leave the single-leg one alone.');

    expect(str_contains($found[0], 'amount_minor, settled_amount_minor, settled_currency, fx_rate_used'))->toBeTrue(
        'The reader flagged a literal but did not name the four columns it found in it: '.implode(' | ', $found),
    );
});

// A declaration of the rate, rather than a read of one: a promoted property, a
// typed property, or a typed parameter. `$this->fxRateUsed` and the named
// argument `fxRateUsed:` both tokenise as T_STRING and are neither.
/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function amountColumnDeclaresTheRate(array $tokens, int $index): bool
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if (trim($text) === '') {
            continue;
        }

        return in_array($text, ['?', 'string', 'null', 'readonly', 'public', 'private', 'protected'], true);
    }

    return false;
}

// The fifth column is not a fifth fact. It is the ratio of the pair stored
// beside it, so a class holding the legs AND a rate of its own has two answers
// to one question and only one of them was derived: CanonicalTransaction
// carried a `fxRateUsed` that toAttributes() stopped reading the moment the
// four columns started leaving through the seam. It survived a round trip
// through the preview cache and was discarded at the end of it, while
// FingerprintRederiveService read the stored rate back out of the row to pass
// it in and DemoTransactionsSeeder kept a constant alive to supply one.
it('declares the rate those two legs derive in exactly one place', function (): void {
    $declaring = [];
    $counted = 0;

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $tokens = BackendSourceFiles::codeTokens($path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$fxRateUsed') {
                continue;
            }
            if (! amountColumnDeclaresTheRate($tokens, $index)) {
                continue;
            }

            $counted++;

            if ($relative !== AMOUNT_COLUMN_SEAM) {
                $declaring[] = $relative.':'.$token[2];
            }
        }
    }

    // The seam declares one. A walk finding none is reading nothing.
    expect($counted)->toBeGreaterThan(0, 'no declaration of $fxRateUsed was found anywhere, and the seam declares one — the token walk is broken, not the tree.');

    expect($declaring)->toBe([], implode("\n  ", [
        'The rate is derived from the two legs, never carried beside them: a class',
        'that declares one of its own is a second answer to the same question, and',
        'the two drift the moment anything writes only the legs. Read it from',
        'TransactionAmount::relate(...)->fxRateUsed, or from a DTO that owns the',
        'legs through CanonicalTransaction::amount(). Offenders:',
        ...$declaring,
    ]));
});

it('lets every transaction be born through that same seam', function (): void {
    $canonical = (string) file_get_contents(base_path('Modules/Ledger/Public/Dto/CanonicalTransaction.php'));

    expect(str_contains($canonical, 'TransactionAmount::relate('))->toBeTrue(
        'CanonicalTransaction no longer relates its two legs through the seam, so the rate beside '
        .'them is whatever its caller passed rather than the ratio of the pair it stores.',
    );

    expect(str_contains($canonical, '$this->amount()->toColumns()'))->toBeTrue(implode("\n  ", [
        'CanonicalTransaction::toAttributes() is the payload every insert into',
        'transactions is made from — import, receipts, the cash book and the',
        'migration promote all reach the table through it. Emitting the four',
        'columns from the constructor arguments hands the invariant back to',
        'whoever built the DTO, and the migration promote did not have it.',
    ]));
});
