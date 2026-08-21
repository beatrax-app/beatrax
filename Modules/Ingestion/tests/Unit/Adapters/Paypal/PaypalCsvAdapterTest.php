<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Public\Dto\StatementSummaryData;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->fixture = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
    $this->adapter = app(PaypalCsvAdapter::class);
});

it('reports its stable format identifier as paypal-csv', function (): void {
    expect($this->adapter->format())->toBe('paypal-csv');
})->group('phase-4');

it('parses the redacted fixture into 41 canonical SourceTransactionDto rows', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->fixture, $this->resolver), false);

    expect($dtos)->toHaveCount(41);
    foreach ($dtos as $dto) {
        expect($dto)->toBeInstanceOf(SourceTransactionDto::class);
        expect($dto->ownIban)->toBe('PAYPAL');
        expect($dto->rawPayload)->toHaveKey('format');
        expect($dto->rawPayload['format'])->toBe('paypal-csv');
    }
})->group('phase-4');

it('emits monotonically increasing sourceRowIndex starting at zero', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->fixture, $this->resolver), false);

    foreach ($dtos as $i => $dto) {
        expect($dto->sourceRowIndex)->toBe($i);
    }
})->group('phase-4');

it('yields the dual-amount pair for the Cloudflare USD chain (FX-direction safety net)', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->fixture, $this->resolver), false);

    $cloudflareUsd = null;
    foreach ($dtos as $dto) {
        if ($dto->counterpartyName === 'Cloudflare Inc' && $dto->currency === 'USD' && $dto->amountMinor === -1046) {
            $cloudflareUsd = $dto;
            break;
        }
    }

    expect($cloudflareUsd)->not->toBeNull();
    /** @var SourceTransactionDto $cloudflareUsd */
    expect($cloudflareUsd->currency)->toBe('USD');
    expect($cloudflareUsd->amountMinor)->toBe(-1046);
    expect($cloudflareUsd->settledCurrency)->toBe('EUR');
    expect($cloudflareUsd->settledAmountMinor)->toBe(-927);
    expect($cloudflareUsd->fxRateUsed)->toBeNull();
})->group('phase-4');

it('exposes a populated StatementSummaryData via statementMetadata() after parse() completes', function (): void {
    iterator_to_array($this->adapter->parse($this->fixture, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->ibanOwner)->toBe('PAYPAL');
    expect($metadata->entryCount)->toBe(41);
    expect($metadata->periodStart)->not->toBeNull();
    expect($metadata->periodEnd)->not->toBeNull();
    expect($metadata->extras)->not->toBeNull();
    /** @var array<string, mixed> $extras */
    $extras = $metadata->extras;
    expect($extras)->toHaveKey('language');
    expect($extras['language'])->toBe('nl');
    expect($extras)->toHaveKey('skippedHoldCount');
    expect($extras['skippedHoldCount'])->toBe(0);
    expect($extras)->toHaveKey('orphanChildCount');
    // The export carries no opening or closing balance row, so there is
    // nothing to reconcile against and the walker counters are the only
    // audit signal.
    expect($extras)->not->toHaveKey('reconciliationStatus');
    expect($extras)->not->toHaveKey('reconciliationGap');
})->group('phase-4');

it('registers under the paypal-csv key in the SourceAdapterRegistry', function (): void {
    $registry = app(SourceAdapterRegistry::class);

    $adapter = $registry->for(PaypalCsvLanguageProfile::FORMAT);

    expect($adapter)->toBeInstanceOf(PaypalCsvAdapter::class);
    expect($registry->supportedFormats())->toContain('paypal-csv');
})->group('phase-4');
