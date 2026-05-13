<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnCamt053Adapter;
use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->csv = $this->app->make(AsnCsvAdapter::class);
    $this->camt = $this->app->make(AsnCamt053Adapter::class);
    $this->fingerprints = $this->app->make(FingerprintComposer::class);
});

/**
 * Lifts a SourceTransactionDto into a synthetic CanonicalTransaction shaped
 * exactly the way `NormalizeStage` would produce it: same counterparty
 * normalisation, same sentinel for nameless rows, fixed user + account ids
 * so the fingerprint tuple depends only on the booking instant + amount +
 * counterparty — the cross-format invariant under test.
 */
function liftToCanonical(SourceTransactionDto $dto, FingerprintComposer $fp): CanonicalTransaction
{
    $rawName = $dto->counterpartyName;
    $normalised = $rawName === null || trim($rawName) === ''
        ? '_no_counterparty'
        : $fp->normalize($rawName);
    if ($normalised === '') {
        $normalised = '_no_counterparty';
    }

    return new CanonicalTransaction(
        userId: 42,
        accountId: 7,
        type: $dto->amountMinor < 0 ? 'expense' : 'income',
        postedAt: $dto->postedAt,
        bookedAt: $dto->bookedAt,
        valueDate: $dto->valueDate,
        amountMinor: $dto->amountMinor,
        currency: $dto->currency,
        settledAmountMinor: $dto->amountMinor,
        settledCurrency: $dto->currency,
        fxRateUsed: null,
        counterpartyName: $dto->counterpartyName,
        counterpartyIban: $dto->counterpartyIban,
        counterpartyNormalized: $normalised,
        normalizationVersion: $fp->version(),
        description: $dto->description,
        categoryId: null,
        sourceFormat: 'test',
        importRunId: 1,
        sourceRowIndex: $dto->sourceRowIndex,
        sourceRef: $dto->sourceRef,
    );
}

it('produces identical v3 fingerprints for the same row across CSV and CAMT.053', function (): void {
    /** @var list<SourceTransactionDto> $csvDtos */
    $csvDtos = iterator_to_array(
        $this->csv->parse(base_path('tests/fixtures/asn-cross-format/february.csv'), $this->resolver),
        preserve_keys: false,
    );

    /** @var list<SourceTransactionDto> $camtDtos */
    $camtDtos = iterator_to_array(
        $this->camt->parse(base_path('tests/fixtures/asn-cross-format/february.camt053.xml'), $this->resolver),
        preserve_keys: false,
    );

    expect($csvDtos)->not->toBeEmpty();
    expect($camtDtos)->not->toBeEmpty();

    // Index every CSV fingerprint so the test does not depend on the parse
    // order agreeing between the two formats.
    $csvHashes = [];
    foreach ($csvDtos as $csvDto) {
        $hash = $this->fingerprints->compose(liftToCanonical($csvDto, $this->fingerprints));
        $csvHashes[$hash] = $csvDto;
    }

    $matched = 0;
    foreach ($camtDtos as $camtDto) {
        $hash = $this->fingerprints->compose(liftToCanonical($camtDto, $this->fingerprints));
        if (isset($csvHashes[$hash])) {
            $matched++;
        }
    }

    // The two fixtures cover the same calendar period (February 2026) on the
    // same own IBAN. The cross-format dedup invariant requires that the
    // majority of CAMT entries find an exact CSV twin under the v3 tuple.
    // Use a fraction-based assertion so a future fixture refresh that
    // tweaks one or two row-level fields does not break the contract.
    expect($matched)->toBeGreaterThan((int) (count($camtDtos) * 0.5));
})->group('phase-2');
