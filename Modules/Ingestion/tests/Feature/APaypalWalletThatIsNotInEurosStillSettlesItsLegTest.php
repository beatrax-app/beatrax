<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(PaypalCsvAdapter::class);
});

/**
 * @return list<SourceTransactionDto>
 */
function parsedPaypalRows(string $fixture): array
{
    /** @var list<SourceTransactionDto> $rows */
    $rows = [];
    foreach (test()->adapter->parse(base_path($fixture), test()->resolver) as $dto) {
        $rows[] = $dto;
    }

    return $rows;
}

function paypalRowRef(string $fixture, string $sourceRef): SourceTransactionDto
{
    return collect(parsedPaypalRows($fixture))
        ->firstOrFail(fn (SourceTransactionDto $dto): bool => $dto->sourceRef === $sourceRef);
}

it('settles a dollar purchase against the pounds the wallet actually paid it with', function (): void {
    $purchase = paypalRowRef(
        'Modules/Ingestion/tests/fixtures/paypal/paypal-gbp-wallet.csv',
        'O-00000000000000034',
    );

    expect($purchase->currency)->toBe('USD')
        ->and($purchase->amountMinor)->toBe(-1046)
        ->and($purchase->settledCurrency)->toBe('GBP')
        ->and($purchase->settledAmountMinor)->toBe(-805);
});

// The pair the file itself publishes is the whole rate: 8,05 over 10,46. No
// provider is consulted and nothing is interpolated, which is the only way a
// leg may carry a settled figure at all.
it('takes the settled figure from the file rather than converting the native one', function (): void {
    $purchase = paypalRowRef(
        'Modules/Ingestion/tests/fixtures/paypal/paypal-gbp-wallet.csv',
        'O-00000000000000034',
    );

    /** @var list<array{type: string, row: array<string, string>}> $events */
    $events = $purchase->rawPayload['events'];
    $legs = array_map(static fn (array $event): string => $event['row']['Bruto '], $events);

    expect($legs)->toContain('-8,05')
        ->and($legs)->toContain('10,46')
        ->and($purchase->settledAmountMinor)->toBe(-805);
});

// A pounds wallet that starts and ends empty nets to zero, and /reconcile
// filters on a non-null closing balance: without a settled leg the dollar
// purchase left the file naming two denominations and publishing neither.
it('publishes a closing balance for a wallet whose balance is not in euros', function (): void {
    parsedPaypalRows('Modules/Ingestion/tests/fixtures/paypal/paypal-gbp-wallet.csv');

    $summary = $this->adapter->statementMetadata();

    expect($summary)->not->toBeNull()
        ->and($summary?->closingBalanceCurrency)->toBe('GBP')
        ->and($summary?->closingBalanceMinor)->toBe(0);
});

it('still settles the euro wallet in euros and closes its statement in them', function (): void {
    $fixture = 'Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv';

    $purchase = paypalRowRef($fixture, 'O-00000000000000034');
    $summary = $this->adapter->statementMetadata();

    expect($purchase->currency)->toBe('USD')
        ->and($purchase->amountMinor)->toBe(-1046)
        ->and($purchase->settledCurrency)->toBe('EUR')
        ->and($purchase->settledAmountMinor)->toBe(-927)
        ->and($summary?->closingBalanceCurrency)->toBe('EUR')
        ->and($summary?->closingBalanceMinor)->toBe(0);
});
