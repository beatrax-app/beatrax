<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

function makeSourceDto(array $overrides = []): SourceTransactionDto
{
    $defaults = [
        'bookedAt' => CarbonImmutable::parse('2026-03-15'),
        'postedAt' => CarbonImmutable::parse('2026-03-15'),
        'valueDate' => CarbonImmutable::parse('2026-03-15'),
        'ownIban' => 'NL57ASNB0123456789',
        'counterpartyIban' => 'NL41BANK0000000099',
        'counterpartyName' => 'Albert Heijn',
        'currency' => 'EUR',
        'amountMinor' => -1299,
        'sourceRef' => 'REF-001',
        'description' => 'AH groceries',
        'rawPayload' => [],
        'sourceRowIndex' => 0,
        'settledAmountMinor' => null,
        'settledCurrency' => null,
        'fxRateUsed' => null,
    ];

    $merged = array_merge($defaults, $overrides);

    return new SourceTransactionDto(
        bookedAt: $merged['bookedAt'],
        postedAt: $merged['postedAt'],
        valueDate: $merged['valueDate'],
        ownIban: $merged['ownIban'],
        counterpartyIban: $merged['counterpartyIban'],
        counterpartyName: $merged['counterpartyName'],
        currency: $merged['currency'],
        amountMinor: $merged['amountMinor'],
        sourceRef: $merged['sourceRef'],
        description: $merged['description'],
        rawPayload: $merged['rawPayload'],
        sourceRowIndex: $merged['sourceRowIndex'],
        settledAmountMinor: $merged['settledAmountMinor'],
        settledCurrency: $merged['settledCurrency'],
        fxRateUsed: $merged['fxRateUsed'],
    );
}

function makeUserForNormalize(): User
{
    $user = new User;
    $user->id = 42;

    return $user;
}

it('substitutes the _no_counterparty sentinel when counterparty name is null', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['counterpartyName' => null]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 7, sourceFormat: 'asn-csv');

    expect($canonical)->toBeInstanceOf(CanonicalTransaction::class);
    expect($canonical->counterpartyNormalized)->toBe('_no_counterparty');
});

it('substitutes the _no_counterparty sentinel when counterparty name is empty', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['counterpartyName' => '   ']);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 7, sourceFormat: 'asn-csv');

    expect($canonical->counterpartyNormalized)->toBe('_no_counterparty');
});

it('substitutes the _no_counterparty sentinel when name is punctuation-only', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['counterpartyName' => '!!!???']);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 7, sourceFormat: 'asn-csv');

    expect($canonical->counterpartyNormalized)->toBe('_no_counterparty');
});

it('maps negative amounts to expense type', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['amountMinor' => -1299]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'asn-csv');

    expect($canonical->type)->toBe('expense');
});

it('maps positive amounts to income type', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['amountMinor' => 250000]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'asn-csv');

    expect($canonical->type)->toBe('income');
});

it('maps zero amounts to adjustment type', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['amountMinor' => 0]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'asn-csv');

    expect($canonical->type)->toBe('adjustment');
});

it('normalises counterparty name via FingerprintComposer (lowercase, diacritics, truncation)', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['counterpartyName' => 'Café Plein']);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'asn-csv');

    expect($canonical->counterpartyNormalized)->toBe('cafe plein');
});

it('preserves user_id, account_id, import_run_id, source_row_index and source_format', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['sourceRowIndex' => 12]);

    $canonical = $stage->run($source, accountId: 99, user: makeUserForNormalize(), importRunId: 7, sourceFormat: 'asn-csv');

    expect($canonical->userId)->toBe(42);
    expect($canonical->accountId)->toBe(99);
    expect($canonical->importRunId)->toBe(7);
    expect($canonical->sourceRowIndex)->toBe(12);
    expect($canonical->sourceFormat)->toBe('asn-csv');
});

it('passes through the sourceFormat supplied by the caller', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $canonical = $stage->run(makeSourceDto(), accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'ics-csv');

    expect($canonical->sourceFormat)->toBe('ics-csv');
});

it('mirrors native amount/currency to settled amount/currency by default', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto(['amountMinor' => -3999, 'currency' => 'EUR']);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'asn-csv');

    expect($canonical->settledAmountMinor)->toBe(-3999);
    expect($canonical->settledCurrency)->toBe('EUR');
    expect($canonical->fxRateUsed)->toBeNull();
});

it('mirrors settled = native and leaves fx_rate_used NULL when source omits the settled pair', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto([
        'amountMinor' => -1500,
        'currency' => 'EUR',
        // The EUR-native ASN row shape: no settled pair supplied at all.
    ]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'asn-csv');

    expect($canonical->settledAmountMinor)->toBe(-1500);
    expect($canonical->settledCurrency)->toBe('EUR');
    expect($canonical->fxRateUsed)->toBeNull();
})->group('phase-3');

it('derives fx_rate_used when source supplies a different settled currency', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto([
        'amountMinor' => 1299,
        'currency' => 'USD',
        'settledAmountMinor' => 1207,
        'settledCurrency' => 'EUR',
    ]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'ics-pdf');

    expect($canonical->amountMinor)->toBe(1299);
    expect($canonical->currency)->toBe('USD');
    expect($canonical->settledAmountMinor)->toBe(1207);
    expect($canonical->settledCurrency)->toBe('EUR');
    // 1207 / 1299 = 0.92917628944... → 0.92917629 at scale 8 with HALF_UP.
    expect($canonical->fxRateUsed)->toBe('0.92917629');
})->group('phase-3');

it('leaves fx_rate_used NULL when source-supplied settled currency equals native currency', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto([
        'amountMinor' => 1299,
        'currency' => 'EUR',
        'settledAmountMinor' => 1299,
        'settledCurrency' => 'EUR',
    ]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'ics-pdf');

    expect($canonical->settledAmountMinor)->toBe(1299);
    expect($canonical->settledCurrency)->toBe('EUR');
    expect($canonical->fxRateUsed)->toBeNull();
})->group('phase-3');

it('leaves fx_rate_used NULL when amountMinor is zero', function (): void {
    $stage = new NormalizeStage(new FingerprintComposer);
    $source = makeSourceDto([
        'amountMinor' => 0,
        'currency' => 'USD',
        'settledAmountMinor' => 0,
        'settledCurrency' => 'EUR',
    ]);

    $canonical = $stage->run($source, accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'ics-pdf');

    expect($canonical->settledAmountMinor)->toBe(0);
    expect($canonical->settledCurrency)->toBe('EUR');
    expect($canonical->fxRateUsed)->toBeNull();
})->group('phase-3');

it('records the normalization version from the FingerprintComposer', function (): void {
    $composer = new FingerprintComposer;
    $stage = new NormalizeStage($composer);
    $canonical = $stage->run(makeSourceDto(), accountId: 1, user: makeUserForNormalize(), importRunId: 1, sourceFormat: 'asn-csv');

    expect($canonical->normalizationVersion)->toBe($composer->version());
});
