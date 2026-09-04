<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvColumnMap;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalDateParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalTransactionRollup;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

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

    // Two DTOs, not one: only the FX legs fold into the purchase. The funding
    // leg is the reader's own money entering PayPal and stands on its own, so
    // the bank-side debit has something to pair against.
    expect($dtos)->toHaveCount(2);

    $dto = collect($dtos)->firstOrFail(fn (SourceTransactionDto $d): bool => $d->sourceRef === 'O-00000000000000034');
    expect($dto->currency)->toBe('USD');
    expect($dto->amountMinor)->toBe(-1046);
    expect($dto->settledCurrency)->toBe('EUR');
    expect($dto->settledAmountMinor)->toBe(-927);
    expect($dto->counterpartyName)->toBe('Cloudflare Inc');

    $fundingDto = collect($dtos)->firstOrFail(fn (SourceTransactionDto $d): bool => $d->sourceRef === 'O-00000000000000033');
    expect($fundingDto->amountMinor)->toBe(927);
    expect($fundingDto->currency)->toBe('EUR');
})->group('phase-4');

it('gives the settled leg the parent payment direction when PayPal booked that leg as a credit', function (): void {
    // Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv lines 8-11.
    // PayPal books the euro leg of an outgoing dollar payment as the credit its
    // OWN euro balance saw, so the two legs of one payment carry opposite signs
    // in the export.
    $parent = paypalRow([
        'Valuta' => 'USD',
        'Bruto ' => '-22,50',
        'Netto' => '-22,50',
        'Transactiereferentie' => 'O-PHASE5-0000000000000010',
        'Naam' => 'Cloudflare Inc',
        'Reference Txn ID' => 'O-PHASE5-0000000000000011',
    ]);
    $fxEur = paypalRow([
        'Omschrijving' => 'Algemene valutaomrekening',
        'Valuta' => 'EUR',
        'Bruto ' => '20,80',
        'Netto' => '20,80',
        'Transactiereferentie' => 'O-PHASE5-0000000000000013',
        'Naam' => '',
        'Reference Txn ID' => 'O-PHASE5-0000000000000010',
    ]);
    $fxUsd = paypalRow([
        'Omschrijving' => 'Algemene valutaomrekening',
        'Valuta' => 'USD',
        'Bruto ' => '-22,50',
        'Netto' => '-22,50',
        'Transactiereferentie' => 'O-PHASE5-0000000000000014',
        'Naam' => '',
        'Reference Txn ID' => 'O-PHASE5-0000000000000010',
    ]);

    $dtos = $this->rollup->rollup([$parent, $fxEur, $fxUsd], 'nl');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->currency)->toBe('USD');
    expect($dtos[0]->amountMinor)->toBe(-2250);
    expect($dtos[0]->settledCurrency)->toBe('EUR');
    expect($dtos[0]->settledAmountMinor)->toBe(-2080);
})->group('phase-4');

it('gives the promoted foreign leg the parent direction when the parent is the euro one', function (): void {
    // The mirrored branch: a EUR parent whose foreign leg becomes the native
    // pair, so the leg PayPal credited must not become a positive native amount
    // against a negative settled one.
    $parent = paypalRow([
        'Valuta' => 'EUR',
        'Bruto ' => '-20,80',
        'Netto' => '-20,80',
        'Transactiereferentie' => 'O-PHASE5-0000000000000020',
        'Naam' => 'Cloudflare Inc',
        'Reference Txn ID' => '',
    ]);
    $fxUsd = paypalRow([
        'Omschrijving' => 'Algemene valutaomrekening',
        'Valuta' => 'USD',
        'Bruto ' => '22,50',
        'Netto' => '22,50',
        'Transactiereferentie' => 'O-PHASE5-0000000000000021',
        'Naam' => '',
        'Reference Txn ID' => 'O-PHASE5-0000000000000020',
    ]);

    $dtos = $this->rollup->rollup([$parent, $fxUsd], 'nl');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->currency)->toBe('USD');
    expect($dtos[0]->amountMinor)->toBe(-2250);
    expect($dtos[0]->settledCurrency)->toBe('EUR');
    expect($dtos[0]->settledAmountMinor)->toBe(-2080);
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
    // An FX leg is the remaining child kind, and the one a month-boundary
    // split can strand: its parent sits in the previous statement file.
    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000010',
            'Omschrijving' => 'Algemene valutaomrekening',
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
        'Omschrijving' => 'Algemene valutaomrekening',
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
    expect($events[1]['type'])->toBe('Algemene valutaomrekening');
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

it('skips a parent row with a malformed Bruto cell and names the place it held', function (): void {
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
    expect($this->rollup->unreadableRowIndexes())->toBe([1]);
})->group('phase-4');

// The skip-the-row catch names InvalidAmountException, so an over-range Bruto
// that raised anything else walked straight past it and took the whole export
// down instead of dropping one row.
it('skips a parent row whose Bruto is wider than the ledger can hold', function (): void {
    $overRange = str_repeat('9', MoneyInput::MAX_WHOLE_DIGITS + 2);

    $rows = [
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000001',
            'Bruto ' => '-8,10',
            'Netto' => '-8,10',
        ]),
        paypalRow([
            'Transactiereferentie' => 'O-00000000000000002',
            'Bruto ' => $overRange.',99',
            'Netto' => '-1,00',
        ]),
    ];

    $dtos = $this->rollup->rollup($rows, 'nl');

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->sourceRef)->toBe('O-00000000000000001')
        ->and($this->rollup->unreadableRowIndexes())->toBe([1]);
});

// A PayPal export can pass language detection and still be missing the amount
// column. Defaulted to "0,00" the payment read as an amount of exactly nothing,
// which parses, so nothing raised and the import reported itself clean.
it('refuses a payment row whose export carries no gross-amount column at all', function (): void {
    $row = paypalRow(['Transactiereferentie' => 'O-00000000000000001']);
    unset($row['Bruto ']);

    $dtos = $this->rollup->rollup([$row], 'nl');

    expect($dtos)->toHaveCount(0);
    expect($this->rollup->unreadableRowIndexes())->toBe([0]);
});

it('refuses a conversion leg whose row carries no gross-amount column', function (): void {
    $parent = paypalRow([
        'Valuta' => 'USD',
        'Bruto ' => '-10,46',
        'Netto' => '-10,46',
        'Transactiereferentie' => 'O-00000000000000034',
        'Reference Txn ID' => '',
    ]);
    $childFx = paypalRow([
        'Omschrijving' => 'Algemene valutaomrekening',
        'Valuta' => 'EUR',
        'Netto' => '-9,27',
        'Transactiereferentie' => 'O-00000000000000035',
        'Reference Txn ID' => 'O-00000000000000034',
    ]);
    unset($childFx['Bruto ']);

    $dtos = $this->rollup->rollup([$parent, $childFx], 'nl');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->settledAmountMinor)->toBeNull();
    expect($this->rollup->unreadableRowIndexes())->toBe([1]);
});

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
    expect($this->rollup->unreadableRowIndexes())->toBe([1]);
})->group('phase-4');

it('produces 82 logical-payment groups when given the full redacted fixture rows', function (): void {
    // 86 rows collapse to 82: 41 purchase parents and the 41 funding legs that
    // settled them, each its own movement. Only the 4 FX legs fold away.
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

    expect($dtos)->toHaveCount(82);
    expect($this->rollup->skippedHoldCount())->toBe(0);
})->group('phase-4');
