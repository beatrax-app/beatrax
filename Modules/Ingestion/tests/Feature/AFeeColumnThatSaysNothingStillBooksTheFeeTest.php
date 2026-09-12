<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// A fee is only booked from the `Kosten` column when the row states nothing
// better. `Netto` is the figure `Saldo` steps by, so `Netto - Bruto` is the fee
// whatever the fee column does — and there are three ways it does nothing:
// the export omits it, the cell is blank, or it reads 0,00 beside a `Netto`
// that says otherwise. All three booked the gross and reported a clean import.
const PAYPAL_FEE_ABSENT = 'Modules/Ingestion/tests/fixtures/paypal/paypal-fee-column-absent.csv';

const PAYPAL_FEE_SILENT = 'Modules/Ingestion/tests/fixtures/paypal/paypal-fee-column-says-nothing.csv';

const PAYPAL_NL_HEADER_WITH_FEE = 'Datum,Tijd,Tijdzone,Omschrijving,Valuta,"Bruto ","Kosten ",Netto,Saldo,'
    .'Transactiereferentie,"Van e-mailadres",Naam,"Naam bank",Bankrekening,Verzendkosten,Btw,'
    .'Factuurreferentie,"Reference Txn ID"';

const PAYPAL_NL_HEADER_NO_NET = 'Datum,Tijd,Tijdzone,Omschrijving,Valuta,"Bruto ","Kosten ",Saldo,'
    .'Transactiereferentie,"Van e-mailadres",Naam,"Naam bank",Bankrekening,Verzendkosten,Btw,'
    .'Factuurreferentie,"Reference Txn ID"';

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
function feeColumnParsed(string $absolutePath): array
{
    /** @var list<SourceTransactionDto> $rows */
    $rows = [];
    foreach (test()->adapter->parse($absolutePath, test()->resolver) as $dto) {
        $rows[] = $dto;
    }

    return $rows;
}

/**
 * @return list<SourceTransactionDto>
 */
function feeColumnRowsFor(string $fixture, string $sourceRef): array
{
    return array_values(array_filter(
        feeColumnParsed(base_path($fixture)),
        static fn (SourceTransactionDto $dto): bool => $dto->sourceRef === $sourceRef,
    ));
}

// Written to a temp file rather than committed, because a cell no parser can
// read is not a shape worth keeping a fixture of — only worth proving against.
function feeColumnTempExport(string $header, string $row): string
{
    $path = tempnam(sys_get_temp_dir(), 'paypal-fee-column-').'.csv';
    file_put_contents($path, $header."\n".$row."\n");

    return $path;
}

function feeColumnPaymentRow(string $bruto, ?string $kosten, ?string $netto): string
{
    $cells = ['4/2/2026', '09:14:02', 'Europe/Berlin', '"Algemene kaartstorting"', 'EUR', '"'.$bruto.'"'];
    if ($kosten !== null) {
        $cells[] = '"'.$kosten.'"';
    }
    if ($netto !== null) {
        $cells[] = '"'.$netto.'"';
    }

    return implode(',', [...$cells, '"0,00"', 'O-00000000000000401', '', '"Klant"', '', '', '"0,00"', '"0,00"', '', '']);
}

// The file's own arithmetic rather than a number written here, for the same
// reason the sibling fee test reads Netto rather than restating it: Netto is
// what PayPal's Saldo column steps by, so it is the independent answer.
function feeColumnTotal(string $fixture, string $column): int
{
    $parser = new PaypalAmountParser;
    $handle = fopen(base_path($fixture), 'r');
    expect($handle)->not->toBeFalse();

    $header = array_map(static fn (string $cell): string => trim($cell), fgetcsv($handle, 0, ',', '"', ''));
    $at = array_flip($header);

    $total = 0;
    while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $total += $parser->parseMinor($record[$at[$column]], $record[$at['Valuta']]);
    }
    fclose($handle);

    return $total;
}

it('books the fee an export with no fee column at all never states', function (): void {
    $rows = feeColumnRowsFor(PAYPAL_FEE_ABSENT, 'O-00000000000000301');

    // Bruto 100,00 and Netto 96,51, and no Kosten column between them. The
    // 3,49 is stated by the pair, not by a cell.
    expect($rows)->toHaveCount(2);
    expect($rows[0]->amountMinor)->toBe(10000);
    expect($rows[1]->amountMinor)->toBe(-349);
    expect($rows[0]->amountMinor + $rows[1]->amountMinor)->toBe(9651);
});

it('leaves a row whose Netto equals its Bruto as the one row it always was', function (): void {
    $rows = feeColumnRowsFor(PAYPAL_FEE_ABSENT, 'O-00000000000000302');

    expect($rows)->toHaveCount(1);
    expect($rows[0]->amountMinor)->toBe(-2500);
});

it('keeps the direction of a fee it derived rather than read', function (): void {
    $rows = feeColumnRowsFor(PAYPAL_FEE_ABSENT, 'O-00000000000000303');

    // Netto -38,75 against Bruto -40,00: PayPal gave 1,25 back, and the
    // subtraction has to come out positive against a negative payment.
    expect($rows[0]->amountMinor)->toBe(-4000);
    expect($rows[1]->amountMinor)->toBe(125);
});

it('books the fee a blank fee cell never states', function (): void {
    $rows = feeColumnRowsFor(PAYPAL_FEE_SILENT, 'O-00000000000000311');

    expect($rows)->toHaveCount(2);
    expect($rows[1]->amountMinor)->toBe(-175);
});

it('books the fee a fee cell reading zero denies', function (): void {
    $rows = feeColumnRowsFor(PAYPAL_FEE_SILENT, 'O-00000000000000312');

    // Kosten 0,00 against Bruto -20,00 and Netto -20,60. Read from the column
    // this row books -2000 and the wallet is short 60.
    expect($rows)->toHaveCount(2);
    expect($rows[1]->amountMinor)->toBe(-60);
});

it('believes a fee cell reading zero when Netto agrees with it', function (): void {
    $rows = feeColumnRowsFor(PAYPAL_FEE_SILENT, 'O-00000000000000313');

    expect($rows)->toHaveCount(1);
    expect($rows[0]->amountMinor)->toBe(-1000);
});

// Every row a file emits, summed against the movement the file states, over
// the fixtures that claim is well defined on. A folded conversion leg carries
// a Netto of its own and is never emitted, so a file holding one would count
// that leg's Netto twice; these three hold none.
dataset('paypal exports with no conversion legs', [
    'fee wallet' => ['Modules/Ingestion/tests/fixtures/paypal/paypal-fee-wallet.csv'],
    'fee column absent' => [PAYPAL_FEE_ABSENT],
    'fee column says nothing' => [PAYPAL_FEE_SILENT],
]);

it('emits rows that sum to what the file says the wallet moved by', function (string $fixture): void {
    $dtos = feeColumnParsed(base_path($fixture));

    $emitted = array_sum(array_map(
        static fn (SourceTransactionDto $dto): int => $dto->amountMinor,
        $dtos,
    ));

    expect($emitted)->toBe(feeColumnTotal($fixture, 'Netto'), $fixture.': the rows emitted do not sum to the Netto column.');
    expect($emitted)->not->toBe(feeColumnTotal($fixture, 'Bruto'), $fixture.': Netto and Bruto agree, so this file cannot fail the claim.');
})->with('paypal exports with no conversion legs');

it('publishes the closing balance the Netto column sums to', function (): void {
    feeColumnParsed(base_path(PAYPAL_FEE_ABSENT));
    $absent = $this->adapter->statementMetadata();

    expect($absent?->closingBalanceMinor)->toBe(3276);
    expect($absent?->closingBalanceMinor)->toBe(feeColumnTotal(PAYPAL_FEE_ABSENT, 'Netto'));

    feeColumnParsed(base_path(PAYPAL_FEE_SILENT));
    $silent = $this->adapter->statementMetadata();

    expect($silent?->closingBalanceMinor)->toBe(1765);
    expect($silent?->closingBalanceMinor)->toBe(feeColumnTotal(PAYPAL_FEE_SILENT, 'Netto'));
});

it('falls back to the fee column when the row states no readable Netto', function (): void {
    $path = feeColumnTempExport(
        PAYPAL_NL_HEADER_WITH_FEE,
        feeColumnPaymentRow('100,00', '-3,49', 'NOT-A-NUMBER'),
    );

    try {
        $rows = feeColumnParsed($path);

        expect($rows)->toHaveCount(2);
        expect($rows[1]->amountMinor)->toBe(-349);
    } finally {
        @unlink($path);
    }
});

// Before the anchor moved to Netto this dropped the whole payment: the fee
// column was the only thing consulted, so a cell nobody could read took a
// payment the row beside it described completely.
it('books a payment whose fee cell cannot be read but whose Netto can', function (): void {
    $path = feeColumnTempExport(
        PAYPAL_NL_HEADER_WITH_FEE,
        feeColumnPaymentRow('100,00', 'NOT-A-NUMBER', '96,51'),
    );

    try {
        $rows = feeColumnParsed($path);

        expect($rows)->toHaveCount(2);
        expect($rows[0]->amountMinor)->toBe(10000);
        expect($rows[1]->amountMinor)->toBe(-349);
        expect($this->adapter->unreadableRowIndexes())->toBe([]);
    } finally {
        @unlink($path);
    }
});

// A fee the file states and nothing can read is still not a fee of zero, so
// the payment is dropped and counted rather than booked short.
it('still refuses a payment when neither the fee nor the movement can be read', function (): void {
    $path = feeColumnTempExport(
        PAYPAL_NL_HEADER_NO_NET,
        feeColumnPaymentRow('100,00', 'NOT-A-NUMBER', null),
    );

    try {
        $rows = feeColumnParsed($path);

        expect($rows)->toBe([]);
        expect($this->adapter->unreadableRowIndexes())->toBe([0]);
    } finally {
        @unlink($path);
    }
});
