<?php

declare(strict_types=1);

use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
use Modules\Ingestion\Public\Exceptions\UnknownPaypalEventTypeException;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;

beforeEach(function (): void {
    $this->map = new PaypalCsvEventTypeMap;
});

// Reflection so the lock-step invariant can be pinned without making the two
// tables public API.
/**
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
    // A 'child-fee' event deliberately has no TRANSACTION_TYPE entry.
    expect(fn () => $this->map->transactionType('Bankstorting naar PP-rekening', 'nl'))
        ->toThrow(MissingPaypalTransactionTypeMapException::class);
})->group('phase-4');

// The three event types below are the funding vocabulary shared with
// PaypalFundingResolver::FUNDING_EVENT_TYPES; the two tables have to agree.

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
    // 'Bankstorting' and 'Bankstorting naar PP-rekening' are two distinct
    // PayPal literals and must not be collapsed into one.
    expect($this->map->classify('Bankstorting naar PP-rekening', 'nl'))->toBe('child-fee');
})->group('phase-4');

// The two miss conditions raise different types so the pipeline can catch them
// apart: absent from MAP is user data, while parent-without-TRANSACTION_TYPE is
// our own inconsistency and raises the narrower subtype.
it('throws the broad UnknownPaypalEventTypeException from classify() for an event type missing from MAP', function (): void {
    expect(fn () => $this->map->classify('Some Bogus Event Type That Is Not In The Map', 'nl'))
        ->toThrow(UnknownPaypalEventTypeException::class);
})->group('phase-4');

it('throws the broad UnknownPaypalEventTypeException from transactionType() for an event type missing from MAP', function (): void {
    expect(fn () => $this->map->transactionType('Some Bogus Event Type That Is Not In The Map', 'nl'))
        ->toThrow(UnknownPaypalEventTypeException::class);

    // toThrow() would also pass on the subtype, so the hierarchy needs
    // catching by hand.
    try {
        $this->map->transactionType('Some Bogus Event Type That Is Not In The Map', 'nl');
        expect(true)->toBeFalse(); // unreachable — the call must throw
    } catch (MissingPaypalTransactionTypeMapException) {
        expect(true)->toBeFalse(); // narrower exception is the wrong type for this case
    } catch (UnknownPaypalEventTypeException) {
        expect(true)->toBeTrue();
    }
})->group('phase-4');
