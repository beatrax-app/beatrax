<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// The scenario-1 fixtures are generated from a locked seed by
// scripts/synthesise_scenario_1_fixtures.php; parsing each one through its
// production adapter here stops a malformed fixture surfacing as a resolver
// bug much later.

beforeEach(function (): void {
    $this->fixtureDir = base_path('Modules/Chains/tests/fixtures/scenario-1');
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
});

it('parses the synthesised ICS PDF via the production IcsPdfAdapter and yields 23 transactions', function (): void {
    $pdf = $this->fixtureDir.'/ics-statement.pdf';
    expect(file_exists($pdf))->toBeTrue();

    /** @var IcsPdfAdapter $adapter */
    $adapter = app(IcsPdfAdapter::class);

    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($adapter->parse($pdf, $this->resolver), false);

    expect($dtos)->toHaveCount(23);

    // Settled-EUR amounts (in cents, signed-negative because Af direction)
    // must sum to -84732 (i.e. €847,32 owed).
    $eurSettledSum = 0;
    foreach ($dtos as $dto) {
        // settledAmountMinor is null on a EUR-native row, where amountMinor is
        // already the settled amount.
        $eurSettledSum += $dto->settledAmountMinor ?? $dto->amountMinor;
    }
    expect($eurSettledSum)->toBe(-84732);

    // Statement metadata should report the period, entry count, and the
    // €847,32 closing balance derived from the four-column summary row.
    $meta = $adapter->statementMetadata();
    expect($meta)->not->toBeNull();
    expect($meta->entryCount)->toBe(23);
    expect($meta->closingBalanceMinor)->toBe(-84732);
});

it('parses the synthesised PayPal CSV via the production PaypalCsvAdapter and surfaces the Bankstorting IBAN hand-off', function (): void {
    $csv = $this->fixtureDir.'/paypal-activity.csv';
    expect(file_exists($csv))->toBeTrue();

    /** @var PaypalCsvAdapter $adapter */
    $adapter = app(PaypalCsvAdapter::class);

    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($adapter->parse($csv, $this->resolver), false);

    // Rollup walker folds parent + child events into logical payments.
    expect(count($dtos))->toBeGreaterThanOrEqual(3);

    // At least one DTO's raw payload must carry the Bankstorting close-out
    // row with the inferable destination IBAN. The walker preserves the
    // child rows on the parent's `events[]` envelope.
    $bankstortingHandOffFound = false;
    foreach ($dtos as $dto) {
        $events = $dto->rawPayload['events'] ?? null;
        if (! is_array($events)) {
            continue;
        }
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            // The Bankstorting row's "Naam" cell carries the literal
            // "Bankstorting" and the bankrekening cell carries the IBAN.
            $haystack = json_encode($event, JSON_UNESCAPED_UNICODE);
            if (is_string($haystack)
                && stripos($haystack, 'Bankstorting') !== false
                && stripos($haystack, 'NL57ASNB0123456789') !== false
            ) {
                $bankstortingHandOffFound = true;
                break 2;
            }
        }
    }

    expect($bankstortingHandOffFound)->toBeTrue();
});

it('parses the synthesised ASN CAMT.053 via the production Camt053Adapter and yields one entry of EUR 847.32', function (): void {
    $xml = $this->fixtureDir.'/asn-camt053.xml';
    expect(file_exists($xml))->toBeTrue();

    /** @var Camt053Adapter $adapter */
    $adapter = app(Camt053Adapter::class);

    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($adapter->parse($xml, $this->resolver), false);

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->currency)->toBe('EUR');
    // A DBIT entry is signed negative. The clean variant settles exactly
    // the ICS statement total (€847,32 = 84732 cents).
    expect($dtos[0]->amountMinor)->toBe(-84732);
});
