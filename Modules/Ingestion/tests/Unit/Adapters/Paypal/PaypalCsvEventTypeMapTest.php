<?php

declare(strict_types=1);

use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
use Modules\Ingestion\Public\Exceptions\UnknownPaypalEventTypeException;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;

/*
 * Coverage for PaypalCsvEventTypeMap.
 *
 * The map carries two correlated tables — `MAP` (event-type → action)
 * and `TRANSACTION_TYPE` (parent-action event-type → Transaction::TYPES
 * enum value). They must stay in lock-step: every event type whose
 * action is 'parent' MUST have a TRANSACTION_TYPE row, and no other
 * event type may appear in TRANSACTION_TYPE.
 *
 * Reflection-based inspection lets the test pin that invariant without
 * exposing the constants on the public API of the class itself.
 */

beforeEach(function (): void {
    $this->map = new PaypalCsvEventTypeMap;
});

/**
 * Pulls the private constants from PaypalCsvEventTypeMap via reflection
 * so the parent-only invariant test does not depend on the constants
 * being public.
 *
 * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}
 */
function paypalMapConstants(): array
{
    $reflection = new ReflectionClass(PaypalCsvEventTypeMap::class);
    /** @var array<string, array<string, string>> $map */
    $map = $reflection->getConstant('MAP');
    /** @var array<string, array<string, string>> $transactionType */
    $transactionType = $reflection->getConstant('TRANSACTION_TYPE');

    return [$map, $transactionType];
}

it('only includes parent-classified event types in TRANSACTION_TYPE', function (): void {
    [$map, $transactionType] = paypalMapConstants();

    foreach ($transactionType as $language => $entries) {
        foreach ($entries as $eventType => $_canonicalType) {
            expect($map[$language][$eventType] ?? null)
                ->toBe('parent', "Event type '{$eventType}' (language '{$language}') appears in TRANSACTION_TYPE but is not classified as 'parent' in MAP.");
        }
    }
})->group('phase-4');

it('covers every parent-classified event type in TRANSACTION_TYPE', function (): void {
    [$map, $transactionType] = paypalMapConstants();

    foreach ($map as $language => $entries) {
        foreach ($entries as $eventType => $action) {
            if ($action !== 'parent') {
                continue;
            }
            expect(isset($transactionType[$language][$eventType]))
                ->toBeTrue("Event type '{$eventType}' (language '{$language}') is classified as 'parent' in MAP but has no TRANSACTION_TYPE entry.");
        }
    }
})->group('phase-4');

it('throws UnknownPaypalEventTypeException for an unmapped event type via classify()', function (): void {
    expect(fn () => $this->map->classify('Niet-bestaande gebeurtenis', 'nl'))
        ->toThrow(UnknownPaypalEventTypeException::class);
})->group('phase-4');

it('throws MissingPaypalTransactionTypeMapException for a non-parent event type via transactionType()', function (): void {
    // 'Bankstorting naar PP-rekening' is mapped as 'child-fee' in MAP,
    // so it deliberately has no TRANSACTION_TYPE entry. Calling
    // transactionType() on it surfaces the narrower internal-
    // inconsistency exception.
    expect(fn () => $this->map->transactionType('Bankstorting naar PP-rekening', 'nl'))
        ->toThrow(MissingPaypalTransactionTypeMapException::class);
})->group('phase-4');

/*
 * Funding-leg coverage. The three event types below are the canonical
 * funding-event vocabulary shared with
 * `PaypalFundingResolver::FUNDING_EVENT_TYPES`. Each must classify as
 * 'parent' (so the rollup walker creates a canonical row for the
 * standalone ASN→PayPal top-up) and resolve to 'transfer_in' (so
 * downstream PairTransferCandidates can match it to the ASN-side
 * transfer_out).
 *
 * The companion regression-guard cases pin the existing 'expense'
 * mappings so the funding-leg additions do not silently regress the
 * purchase-event arm.
 */

it('classifies Bankstorting (standalone funding parent) as parent', function (): void {
    expect($this->map->classify('Bankstorting', 'nl'))->toBe('parent');
})->group('phase-4');

it('classifies General Withdrawal as parent', function (): void {
    expect($this->map->classify('General Withdrawal', 'nl'))->toBe('parent');
})->group('phase-4');

it('classifies Transfer to bank as parent', function (): void {
    expect($this->map->classify('Transfer to bank', 'nl'))->toBe('parent');
})->group('phase-4');

it('resolves Bankstorting to transfer_in via transactionType()', function (): void {
    expect($this->map->transactionType('Bankstorting', 'nl'))->toBe('transfer_in');
})->group('phase-4');

it('resolves General Withdrawal to transfer_in via transactionType()', function (): void {
    expect($this->map->transactionType('General Withdrawal', 'nl'))->toBe('transfer_in');
})->group('phase-4');

it('resolves Transfer to bank to transfer_in via transactionType()', function (): void {
    expect($this->map->transactionType('Transfer to bank', 'nl'))->toBe('transfer_in');
})->group('phase-4');

// Regression guards — the funding-leg additions must not shadow the
// existing purchase-event mappings.
it('preserves the expense mapping for the NL pre-approved-billing parent', function (): void {
    expect($this->map->transactionType('Vooraf goedgekeurde betaling – rekening betaald door gebruiker', 'nl'))->toBe('expense');
})->group('phase-4');

it('preserves the expense mapping for Express Checkout-betaling', function (): void {
    expect($this->map->transactionType('Express Checkout-betaling', 'nl'))->toBe('expense');
})->group('phase-4');

it('keeps the child-fee classification for the localised Bankstorting-naar-PP-rekening child row', function (): void {
    // The standalone 'Bankstorting' parent and the child-fee
    // 'Bankstorting naar PP-rekening' are two distinct PayPal event-type
    // literals. The funding-leg additions must NOT collapse them — the
    // child variant remains 'child-fee' and continues to ride under its
    // parent in the rollup walker.
    expect($this->map->classify('Bankstorting naar PP-rekening', 'nl'))->toBe('child-fee');
})->group('phase-4');

// Exception narrowest-type contract — `classify()` raises the broad
// supertype, `transactionType()` raises the narrower subtype.
// `MissingPaypalTransactionTypeMapException` extends
// `UnknownPaypalEventTypeException`, so we assert the narrowest type
// explicitly per the contract described in the exception PHPDoc.
it('throws the broad UnknownPaypalEventTypeException from classify() for an event type missing from MAP', function (): void {
    expect(fn () => $this->map->classify('Some Bogus Event Type That Is Not In The Map', 'nl'))
        ->toThrow(UnknownPaypalEventTypeException::class);
})->group('phase-4');

it('throws the narrower MissingPaypalTransactionTypeMapException from transactionType() for an event type missing from TRANSACTION_TYPE', function (): void {
    expect(fn () => $this->map->transactionType('Some Bogus Event Type That Is Not In The Map', 'nl'))
        ->toThrow(MissingPaypalTransactionTypeMapException::class);
})->group('phase-4');
