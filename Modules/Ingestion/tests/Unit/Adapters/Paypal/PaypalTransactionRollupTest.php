<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvColumnMap;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalDateParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalTransactionRollup;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;

beforeEach(function (): void {
    $this->rollup = new PaypalTransactionRollup(
        events: new PaypalCsvEventTypeMap,
        amounts: new PaypalAmountParser,
        dates: new PaypalDateParser,
        columns: new PaypalCsvColumnMap,
    );
});

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function paypalRow(array $overrides): array
{
    $defaults = [
        'Datum' => '4/1/2026',
        'Tijd' => '12:00:00',
        'Tijdzone' => 'Europe/Berlin',
        'Omschrijving' => 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker',
        'Valuta' => 'EUR',
        'Bruto ' => '-8,10',
        'Kosten ' => '0,00',
        'Netto' => '-8,10',
        'Saldo' => '-8,10',
        'Transactiereferentie' => '',
        'Van e-mailadres' => 'kaarthouder@example.test',
        'Naam' => 'Google Cloud EMEA Limited',
        'Naam bank' => '',
        'Bankrekening' => '',
        'Verzendkosten' => '0,00',
        'Btw' => '0,00',
        'Factuurreferentie' => '',
        'Reference Txn ID' => '',
    ];

    return array_merge($defaults, $overrides);
}

it('rolls up a single flat parent payment row into one canonical DTO', function (): void {
    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000001',
            'Bruto ' => '-8,10',
            'Netto' => '-8,10',
        ]),
    ];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0])->toBeInstanceOf(SourceTransactionDto::class);
    expect($dtos[0]->amountMinor)->toBe(-810);
    expect($dtos[0]->currency)->toBe('EUR');
    expect($dtos[0]->settledAmountMinor)->toBeNull();
    expect($dtos[0]->settledCurrency)->toBeNull();
    expect($dtos[0]->fxRateUsed)->toBeNull();
    expect($dtos[0]->sourceRef)->toBe('O-00000000000000001');
    expect($dtos[0]->ownIban)->toBe('PAYPAL');
})->group('phase-4');

it('folds a 4-row USD currency-conversion chain into ONE DTO with the dual-amount pair populated', function (): void {
    // The chain shape is copied from paypal-sample-1.csv lines 28-29 + 84-85:
    // a USD parent whose own Reference Txn ID points outside the file, plus a
    // funding row and both FX legs pointing back at the parent.
    $parent = paypalRow([
        'Omschrijving' => 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker',
        'Valuta' => 'USD',
        'Bruto ' => '-10,46',
        'Netto' => '-10,46',
        'Transactiereferentie' => 'O-00000000000000034',
        'Naam' => 'Cloudflare Inc',
        'Reference Txn ID' => 'O-00000000000000096',   // orphan — parent treated as standalone
    ]);
    $funding = paypalRow([
        'Omschrijving' => 'Bankstorting naar PP-rekening',
        'Valuta' => 'EUR',
        'Bruto ' => '9,27',
        'Netto' => '9,27',
        'Transactiereferentie' => 'O-00000000000000033',
        'Naam' => '',
        'Reference Txn ID' => 'O-00000000000000034',
    ]);
    $fxEur = paypalRow([
        'Omschrijving' => 'Algemene valutaomrekening',
        'Valuta' => 'EUR',
        'Bruto ' => '-9,27',
        'Netto' => '-9,27',
        'Transactiereferentie' => 'O-00000000000000035',
        'Naam' => '',
        'Reference Txn ID' => 'O-00000000000000034',
    ]);
    $fxUsd = paypalRow([
        'Omschrijving' => 'Algemene valutaomrekening',
        'Valuta' => 'USD',
        'Bruto ' => '10,46',
        'Netto' => '10,46',
        'Transactiereferentie' => 'O-00000000000000097',
        'Naam' => '',
        'Reference Txn ID' => 'O-00000000000000034',
    ]);

    // The parent deliberately does not come first: the foreign leg has to be
    // found by `Currency != 'EUR'`, never by row order.
    $rows = [$funding, $fxEur, $parent, $fxUsd];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(1);
    $dto = $dtos[0];
    expect($dto->currency)->toBe('USD');
    expect($dto->amountMinor)->toBe(-1046);
    expect($dto->settledCurrency)->toBe('EUR');
    expect($dto->settledAmountMinor)->toBe(-927);
    expect($dto->fxRateUsed)->toBeNull();
    expect($dto->counterpartyName)->toBe('Cloudflare Inc');
    expect($dto->sourceRef)->toBe('O-00000000000000034');
})->group('phase-4');

it('drops rows whose event type classifies as skip and bumps the hold counter', function (): void {
    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000001',
            'Omschrijving' => 'Hold',
        ]),
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000002',
            'Omschrijving' => 'Authorization',
        ]),
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000003',
            'Omschrijving' => 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker',
        ]),
    ];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(1);
    expect($this->rollup->skippedHoldCount())->toBe(2);
})->group('phase-4');

it('promotes an orphan child whose parent is absent from the file to a standalone parent', function (): void {
    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000010',
            'Omschrijving' => 'Bankstorting naar PP-rekening',
            'Bruto ' => '8,10',
            'Netto' => '8,10',
            'Reference Txn ID' => 'O-99999999999999999',   // not in file
        ]),
    ];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(1);
    expect($this->rollup->orphanChildCount())->toBe(1);
})->group('phase-4');

it('assigns the parent Transaction ID as the canonical DTO sourceRef', function (): void {
    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000042',
        ]),
    ];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos[0]->sourceRef)->toBe('O-00000000000000042');
})->group('phase-4');

it('writes a rawPayload event manifest carrying parent + children rows in CSV order', function (): void {
    $parent = paypalRow([
        'Transactiereferentie' => 'O-00000000000000001',
        'Reference Txn ID' => '',
    ]);
    $child = paypalRow([
        'Transactiereferentie' => 'O-00000000000000002',
        'Omschrijving' => 'Bankstorting naar PP-rekening',
        'Bruto ' => '8,10',
        'Netto' => '8,10',
        'Reference Txn ID' => 'O-00000000000000001',
    ]);

    $dtos = $this->rollup->rollup([$parent, $child], 'nl');

    expect($dtos)->toHaveCount(1);
    $payload = $dtos[0]->rawPayload;
    expect($payload)->toHaveKey('format');
    expect($payload['format'])->toBe('paypal-csv');
    expect($payload)->toHaveKey('events');
    /** @var list<array<string, mixed>> $events */
    $events = $payload['events'];
    expect($events)->toHaveCount(2);
    expect($events[0]['type'])->toBe('Vooraf goedgekeurde betaling – rekening betaald door gebruiker');
    expect($events[1]['type'])->toBe('Bankstorting naar PP-rekening');
})->group('phase-4');

it('emits monotonically increasing sourceRowIndex over the rolled-up canonical rows', function (): void {
    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000001',
            'Bruto ' => '-1,00', 'Netto' => '-1,00',
        ]),
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000002',
            'Bruto ' => '-2,00', 'Netto' => '-2,00',
        ]),
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000003',
            'Bruto ' => '-3,00', 'Netto' => '-3,00',
        ]),
    ];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(3);
    foreach ($dtos as $i => $dto) {
        expect($dto->sourceRowIndex)->toBe($i);
    }
})->group('phase-4');

it('skips a parent row with a malformed Bruto cell and bumps skippedMalformedRowCount', function (): void {
    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000001',
            'Bruto ' => '-8,10',
            'Netto' => '-8,10',
        ]),
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000002',
            'Bruto ' => 'NOT-A-NUMBER',
            'Netto' => '-1,00',
        ]),
    ];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->sourceRef)->toBe('O-00000000000000001');
    expect($this->rollup->skippedMalformedRowCount())->toBe(1);
})->group('phase-4');

it('drops a malformed FX child but still emits the parent DTO without the FX pair filled in', function (): void {
    $parent = paypalRow([
        'Omschrijving' => 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker',
        'Valuta' => 'USD',
        'Bruto ' => '-10,46',
        'Netto' => '-10,46',
        'Transactiereferentie' => 'O-00000000000000034',
        'Naam' => 'Cloudflare Inc',
        'Reference Txn ID' => '',
    ]);
    $badChildFx = paypalRow([
        'Omschrijving' => 'Algemene valutaomrekening',
        'Valuta' => 'EUR',
        'Bruto ' => '1,234.56',  // US-locale period decimal — rejected by the NL parser
        'Netto' => '-9,27',
        'Transactiereferentie' => 'O-00000000000000035',
        'Naam' => '',
        'Reference Txn ID' => 'O-00000000000000034',
    ]);

    $dtos = $this->rollup->rollup([$parent, $badChildFx], 'nl');

    expect($dtos)->toHaveCount(1);
    $dto = $dtos[0];
    expect($dto->currency)->toBe('USD');
    expect($dto->amountMinor)->toBe(-1046);
    expect($dto->settledAmountMinor)->toBeNull();
    expect($dto->settledCurrency)->toBeNull();
    expect($this->rollup->skippedMalformedRowCount())->toBe(1);
})->group('phase-4');

it('produces 41 logical-payment groups when given the full redacted fixture rows', function (): void {
    // 86 rows collapse to 41: 39 parents with one child each, plus 2 parents
    // with three children.
    $fixture = base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv');
    $handle = fopen($fixture, 'r');
    if ($handle === false) {
        throw new RuntimeException('Could not open PayPal fixture.');
    }

    try {
        $rawHeader = fgetcsv($handle, 0, ',', '"', '');
        if ($rawHeader === false) {
            throw new RuntimeException('Empty PayPal fixture.');
        }
        // Strip a leading BOM from the first header cell so league/csv's
        // associative key matches the verbatim column names.
        $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawHeader[0]);

        $rows = [];
        while (($r = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $assoc = [];
            foreach ($rawHeader as $i => $key) {
                $assoc[(string) $key] = (string) ($r[$i] ?? '');
            }
            $rows[] = $assoc;
        }
    } finally {
        fclose($handle);
    }

    expect($rows)->toHaveCount(86);

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(41);
    expect($this->rollup->skippedHoldCount())->toBe(0);
})->group('phase-4');
