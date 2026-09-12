<?php

declare(strict_types=1);

use Modules\Ledger\Public\Support\StatementDifference;

// Three screens render this figure and each would otherwise decide for itself
// what an absent key means, which way the sign runs and whether a hundred is
// involved. The parser publishes only a non-zero answer, and only where the
// arithmetic was checkable at all — so the reading of the column carries as
// much of the contract as the writing of it does.

it('reads a positive difference as the rows landing below the stated closing balance', function (): void {
    $difference = StatementDifference::readFrom(['statementDifferenceMinor' => 1200], 'EUR');

    expect($difference?->copyKey())->toBe('core::statement.falls_short_of_closing')
        ->and($difference?->amount())->toBe('€12.00');
});

it('reads a negative difference as the rows landing above it', function (): void {
    $difference = StatementDifference::readFrom(['statementDifferenceMinor' => -1200], 'EUR');

    expect($difference?->copyKey())->toBe('core::statement.overshoots_closing')
        ->and($difference?->amount())->toBe('€12.00');
});

// The parser never publishes a zero, so one in the column is a row written by
// something else. Rendered, it reads "does not add up, by nothing".
it('refuses a zero, which is not a difference', function (): void {
    expect(StatementDifference::readFrom(['statementDifferenceMinor' => 0], 'EUR'))->toBeNull();
});

// Absent on a statement that added up AND on one there was nothing to check
// against. Neither is something to tell a reader, and a screen that read the
// absence as "we did not look" would say so over a statement that balanced.
it('says nothing where the key is absent', function (): void {
    expect(StatementDifference::readFrom(['multiStatement' => true], 'EUR'))->toBeNull()
        ->and(StatementDifference::readFrom(null, 'EUR'))->toBeNull();
});

// Minor units with no currency beside them are an integer, not money, and
// there is no figure to put in a sentence. Refused here rather than rendered
// against a guessed denomination.
it('says nothing where the row states no currency to read the figure at', function (): void {
    expect(StatementDifference::readFrom(['statementDifferenceMinor' => 1200], null))->toBeNull()
        ->and(StatementDifference::readFrom(['statementDifferenceMinor' => 1200], 'ZZZ'))->toBeNull();
});

// A yen has no minor unit. Divided by a hundred on the way to the screen, a
// gap of 1 200 yen would be reported as twelve of them.
it('renders a zero-decimal currency at its own scale', function (): void {
    expect(StatementDifference::readFrom(['statementDifferenceMinor' => 1200], 'JPY')?->amount())
        ->toBe('¥1,200');
});

// The reconcile prefill holds the figure it read on an earlier round trip and
// has no row in hand by the time it renders.
it('reads the same answer from a figure already taken off a row', function (): void {
    expect(StatementDifference::ofMinor(1200, 'EUR')?->copyKey())
        ->toBe(StatementDifference::readFrom(['statementDifferenceMinor' => 1200], 'EUR')?->copyKey())
        ->and(StatementDifference::ofMinor(null, 'EUR'))->toBeNull();
});

// The table is read through a query builder as often as through the model, and
// the two hand the column over in different shapes.
it('reads the column as the JSON text a query builder returns', function (): void {
    $difference = StatementDifference::readFrom('{"statementDifferenceMinor":-1200}', 'EUR');

    expect($difference?->copyKey())->toBe('core::statement.overshoots_closing')
        ->and($difference?->amount())->toBe('€12.00');
});
