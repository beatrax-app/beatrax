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

it('parses the redacted fixture into 82 canonical SourceTransactionDto rows', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->fixture, $this->resolver), false);

    expect($dtos)->toHaveCount(82);
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
})->group('phase-4');

it('exposes a populated StatementSummaryData via statementMetadata() after parse() completes', function (): void {
    iterator_to_array($this->adapter->parse($this->fixture, $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();

    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->ibanOwner)->toBe('PAYPAL');
    expect($metadata->entryCount)->toBe(82);
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

    // PayPal is the only format that SUMS its closing balance, so the target
    // /reconcile shows is only as complete as the rows yielded. It closes at
    // zero because every purchase in this statement was funded on the spot:
    // the wallet ends the period exactly where it started.
    expect($metadata->closingBalanceCurrency)->toBe('EUR');
    expect($metadata->closingBalanceMinor)->toBe(0);
    expect($metadata->openingBalanceCurrency)->toBe('EUR');
    expect($metadata->openingBalanceMinor)->toBe(0);
})->group('phase-4');

// The closing figure is what /reconcile compares the cleared rows against, and
// the rows land in the ledger at their settled leg. Anything else is a gap the
// reader is told to close by toggling rows, and no row can close it.
it('closes at exactly the total the rows it yielded will settle for', function (): void {
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($this->fixture, $this->resolver), false);

    $settledTotal = 0;
    foreach ($dtos as $dto) {
        expect($dto->settledCurrency ?? $dto->currency)->toBe('EUR');
        $settledTotal += $dto->settledAmountMinor ?? $dto->amountMinor;
    }

    $metadata = $this->adapter->statementMetadata();
    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->closingBalanceMinor)->toBe($settledTotal);
})->group('phase-4');

// The closing figure names a currency, so it has to be the one the rows are
// in. Stamped EUR whatever they were, a dollar wallet reconciled against a
// euro target.
it('carries the currency the rows settled in rather than stamping euro on it', function (): void {
    iterator_to_array($this->adapter->parse(paypalFixtureOfLines($this->fixture, [84]), $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();
    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->closingBalanceCurrency)->toBe('USD');
    expect($metadata->closingBalanceMinor)->toBe(-1046);
})->group('phase-4');

// A wallet whose rows settle in more than one denomination has no single
// closing figure. Reported as one anyway it becomes a reconciliation target
// nothing can reach, so the adapter says nothing instead.
it('reports no closing balance at all when the rows settle in more than one currency', function (): void {
    iterator_to_array($this->adapter->parse(paypalFixtureOfLines($this->fixture, [2, 84]), $this->resolver), false);

    $metadata = $this->adapter->statementMetadata();
    expect($metadata)->toBeInstanceOf(StatementSummaryData::class);
    /** @var StatementSummaryData $metadata */
    expect($metadata->closingBalanceMinor)->toBeNull();
    expect($metadata->closingBalanceCurrency)->toBeNull();
    expect($metadata->openingBalanceMinor)->toBeNull();
})->group('phase-4');

it('registers under the paypal-csv key in the SourceAdapterRegistry', function (): void {
    $registry = app(SourceAdapterRegistry::class);

    $adapter = $registry->for(PaypalCsvLanguageProfile::FORMAT);

    expect($adapter)->toBeInstanceOf(PaypalCsvAdapter::class);
    expect($registry->supportedFormats())->toContain('paypal-csv');
})->group('phase-4');

/**
 * @param  list<int>  $lineNumbers  1-based data lines to keep, header always included
 */
function paypalFixtureOfLines(string $source, array $lineNumbers): string
{
    $lines = file($source) ?: [];
    $body = '';
    foreach ($lineNumbers as $number) {
        $body .= $lines[$number - 1] ?? '';
    }

    $path = tempnam(sys_get_temp_dir(), 'paypal-slice-').'.csv';
    file_put_contents($path, ($lines[0] ?? '').$body);
    register_shutdown_function(static fn (): bool => @unlink($path));

    return $path;
}
