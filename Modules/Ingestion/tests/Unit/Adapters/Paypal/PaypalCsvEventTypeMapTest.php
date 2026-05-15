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
