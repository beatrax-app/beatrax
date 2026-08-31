<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
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

    $this->csv = $this->app->make(SourceAdapterRegistry::class)->for(CsvPresetRegistry::ASN);
    $this->camt = $this->app->make(Camt053Adapter::class);
    $this->fingerprints = $this->app->make(FingerprintComposer::class);
});

// The user and account ids are fixed so the fingerprint tuple varies only by
// booking instant, amount and counterparty — the cross-format invariant.
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

    // Indexed by hash so the two formats need not agree on parse order.
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

    // Both fixtures are February 2026 on the same own IBAN, so nearly every
    // CAMT entry should find a CSV twin. 95% rather than 100% leaves room for
    // the handful of genuinely format-specific rows, while a name- or
    // date-normalisation regression halves the rate and trips this.
    expect($matched)->toBeGreaterThanOrEqual((int) ceil(count($camtDtos) * 0.95));
})->group('phase-2');
