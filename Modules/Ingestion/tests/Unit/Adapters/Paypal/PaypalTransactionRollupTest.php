<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvColumnMap;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalDateParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalTransactionRollup;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;

/*
 * Coverage for the three-pass PayPal Transaction-ID rollup walker.
 *
 * The walker is the headline complexity of Phase 4 Wave 1: it folds a
 * flat list of PayPal Activity Download rows (parents + child-fee +
 * child-fx siblings) into one SourceTransactionDto per logical payment.
 * Empirical chain shapes sourced from Wave 0 findings (04-WAVE-0-FINDINGS.md
 * sections c, d, e):
 *
 *   - Single-level depth — parents have children, children never have
 *     grandchildren
 *   - Two orientations — parent's Reference Txn ID empty OR orphan
 *   - 4-row chain per USD purchase (parent USD + Bankstorting + EUR FX
 *     + USD FX, all sharing the parent's Transaction ID via Reference
 *     Txn ID)
 *
 * Pitfall 2 safety net is tested explicitly: when a parent USD + EUR FX
 * pair share a Reference Txn ID, the walker MUST identify the foreign
 * leg by `Currency != 'EUR'` rather than by row order.
 */

beforeEach(function (): void {
    $this->rollup = new PaypalTransactionRollup(
        events: new PaypalCsvEventTypeMap,
        amounts: new PaypalAmountParser,
        dates: new PaypalDateParser,
        columns: new PaypalCsvColumnMap,
    );
});

/**
 * Build one raw row mirroring the empirical NL PayPal column layout.
 * Convenience wrapper so each test below stays focused on the shape under
 * test rather than on repeated array construction.
 *
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
    // Empirical Wave 0 chain shape from paypal-sample-1.csv lines 28-29 + 84-85:
    // - parent USD -10,46
    // - Bankstorting EUR  9,27 (funding-source)
    // - EUR Algemene valutaomrekening -9,27 (settled leg)
    // - USD Algemene valutaomrekening  10,46 (FX leg, USD in)
    //
    // The parent's Reference Txn ID is OUTSIDE the file (orphan) and
    // the children point BACK to the parent's Transaction ID.
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

    // Reorder the rows so the parent does NOT come first — this is the
    // Pitfall 2 safety net: the walker MUST identify the foreign leg via
    // Currency != 'EUR', not by row order.
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
    // A single Bankstorting child row whose Reference Txn ID points at
    // a Transaction ID that is NOT in this file. The walker treats it
    // as a standalone parent + bumps the orphan counter.
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

it('produces 41 logical-payment groups when given the full Wave 0 fixture rows', function (): void {
    // End-to-end: load the 86-row redacted fixture and assert the
    // walker collapses it into exactly 41 canonical DTOs — the number
    // Wave 0 reported (04-WAVE-0-FINDINGS.md section c "Reference Txn
    // ID chain shapes": 39 parents w/ 1 child + 2 parents w/ 3 children
    // = 41 distinct logical-payment groups).
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
    // The fixture's 40 orphan-ref rows are TREATED as standalone parents
    // by the walker — only counts as "orphan" if the row is a CHILD-classified
    // type pointing at an absent parent. In the fixture every orphan-ref
    // row IS a child-classified Bankstorting / Algemene valutaomrekening /
    // Algemene kaartstorting row whose RefId points to a parent inside the
    // file. Wave 0 reports the only true orphans appear inside child rows
    // whose RefId is to a parent outside this period; section (c) confirms
    // 40 rows point OUTSIDE the file — most are parent rows whose RefId
    // is a billing-agreement ID. The walker's contract is: a row whose
    // RefId is absent AND the row is classified parent stays a parent;
    // a row whose RefId is absent AND the row is classified child becomes
    // a standalone parent and gets counted as orphan.
})->group('phase-4');
