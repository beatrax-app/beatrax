<?php

declare(strict_types=1);

use Modules\Anomaly\Internal\Support\BackwardOnly;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\Support\NewestTransactionFirst;

/**
 * @link ../../.docs/architecture/an-ordering-that-picks.md#a-comparison-over-the-same-key
 */

// A read that asks "is there a charge strictly BEFORE this one" needs both
// halves of one key: the comparison that decides which rows are candidates and
// the ordering that picks among them. They are two hand-written column lists,
// and a pair that drifts apart still runs — it just cuts on a key it did not
// order by, which is the defect the row value was introduced to end.

/**
 * @return list<string>
 */
function rowValueKeyColumns(string $clause): array
{
    return array_values(array_filter(array_map(
        static fn (string $term): string => trim(PatternScan::replace('/\s+(asc|desc)\s*$/i', '', trim($term, " \t()"))),
        explode(',', $clause),
    )));
}

it('spells one key as an ordering and as a row value, in one sequence', function (): void {
    $ordered = rowValueKeyColumns(NewestTransactionFirst::ACROSS_ACCOUNTS);
    $compared = rowValueKeyColumns(NewestTransactionFirst::KEY_ACROSS_ACCOUNTS);

    expect($compared)->toBe($ordered, implode("\n  ", [
        'ACROSS_ACCOUNTS ranks and KEY_ACROSS_ACCOUNTS compares, and a reader that cuts on one while',
        'ordering by the other picks a row that is not the nearest. Change both, in the same sequence.',
    ]))->and($ordered)->toBe([
        'posted_at',
        'booked_at',
        'amount_minor',
        'currency',
        'counterparty_normalized',
        'occurrence_ordinal',
        NewestTransactionFirst::ACCOUNT.'.iban',
    ], 'transactions_fingerprint_uq minus the two columns a device counts for itself, plus the account named by what it IS.');
});

// A column added to the key without a `?` beside it is a SQL error on the one
// path that runs the query, and a `?` without a column silently compares the
// wrong pairs — neither shows anywhere else.
it('binds one value per column of the key it compares against', function (): void {
    expect(BackwardOnly::COMPARISON)->toStartWith(NewestTransactionFirst::KEY_ACROSS_ACCOUNTS)
        ->and(substr_count(BackwardOnly::COMPARISON, '?'))->toBe(
            count(rowValueKeyColumns(NewestTransactionFirst::KEY_ACROSS_ACCOUNTS)),
            'The anchor side of the comparison names a different number of values than the key has columns.',
        )->and(BackwardOnly::anchorKey([]))->toHaveCount(
            count(rowValueKeyColumns(NewestTransactionFirst::KEY_ACROSS_ACCOUNTS)),
            'anchorKey() binds a different number of values than the comparison has placeholders.',
        );
});

it('ends neither half of the key on a column a device counts for itself', function (): void {
    foreach ([NewestTransactionFirst::ACROSS_ACCOUNTS, NewestTransactionFirst::KEY_ACROSS_ACCOUNTS] as $clause) {
        foreach (rowValueKeyColumns($clause) as $column) {
            expect($column)->not->toBe('id')
                ->and($column)->not->toBe('account_id')
                ->and($column)->not->toBe('user_id');
        }
    }
});
