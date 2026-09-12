<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Paypal\PaypalAmountParser;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvColumnMap;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvLanguageProfile;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;
use Modules\Ledger\Public\Enums\TransactionType;

// Every fixture shipped before this one carries "Kosten 0,00" on every row,
// which is why a wallet booking Bruto passed for four months: a file whose fee
// is always nothing cannot tell a reader of Bruto from a reader of Netto.
// paypal-fee-wallet.csv is the first that can.
const PAYPAL_FEE_WALLET = 'Modules/Ingestion/tests/fixtures/paypal/paypal-fee-wallet.csv';

const PAYPAL_FEE_CONVERTED = 'Modules/Ingestion/tests/fixtures/paypal/paypal-fee-on-a-converted-payment.csv';

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
function paypalFeeParsed(string $fixture): array
{
    /** @var list<SourceTransactionDto> $rows */
    $rows = [];
    foreach (test()->adapter->parse(base_path($fixture), test()->resolver) as $dto) {
        $rows[] = $dto;
    }

    return $rows;
}

/**
 * @return list<SourceTransactionDto>
 */
function paypalFeeRowsFor(string $fixture, string $sourceRef): array
{
    return array_values(array_filter(
        paypalFeeParsed($fixture),
        static fn (SourceTransactionDto $dto): bool => $dto->sourceRef === $sourceRef,
    ));
}

// The file's own arithmetic, never a number written here: Netto is the column
// PayPal's Saldo steps by, so it is the independent answer to what the wallet
// moved. Restating it by hand would only restate the formula under test.
function paypalFeeColumnTotal(string $fixture, string $column): int
{
    $parser = new PaypalAmountParser;
    $handle = fopen(base_path($fixture), 'r');
    expect($handle)->not->toBeFalse();

    $header = fgetcsv($handle, 0, ',', '"', '');
    expect($header)->toBeArray();
    $header = array_map(static fn (string $cell): string => trim($cell), $header);
    $at = array_flip($header);

    $total = 0;
    while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $total += $parser->parseMinor($record[$at[$column]], $record[$at['Valuta']]);
    }
    fclose($handle);

    return $total;
}

it('books the fee PayPal took as a row beside the payment it was charged on', function (): void {
    $rows = paypalFeeRowsFor(PAYPAL_FEE_WALLET, 'O-00000000000000101');

    // Bruto 100,00 / Kosten -3,49 / Netto 96,51 — the wallet gained 96,51, and
    // before this change the single row it produced claimed 100,00.
    expect($rows)->toHaveCount(2);
    expect($rows[0]->amountMinor)->toBe(10000);
    expect($rows[1]->amountMinor)->toBe(-349);
    expect($rows[0]->amountMinor + $rows[1]->amountMinor)->toBe(9651);
    expect($rows[1]->currency)->toBe('EUR');
});

it('leaves a payment PayPal charged nothing on as the one row it always was', function (): void {
    $rows = paypalFeeRowsFor(PAYPAL_FEE_WALLET, 'O-00000000000000103');

    expect($rows)->toHaveCount(1);
    expect($rows[0]->amountMinor)->toBe(-1000);
});

// A fee row derived by mirroring the payment's sign gives +3,49 on the credit
// and -1,25 on the refund; one derived by abs() gives -1,25 on the refund. The
// column already carries the sign, so the pair below fails both bugs.
it('reads the sign of the fee off the column rather than off the payment it sits on', function (): void {
    $credited = paypalFeeRowsFor(PAYPAL_FEE_WALLET, 'O-00000000000000101');
    $refunded = paypalFeeRowsFor(PAYPAL_FEE_WALLET, 'O-00000000000000104');

    expect($credited[0]->amountMinor)->toBeGreaterThan(0);
    expect($credited[1]->amountMinor)->toBe(-349);

    expect($refunded[0]->amountMinor)->toBe(-4000);
    expect($refunded[1]->amountMinor)->toBe(125);
});

it('lands the statement on the balance its own Netto column sums to', function (): void {
    $dtos = paypalFeeParsed(PAYPAL_FEE_WALLET);
    $summary = $this->adapter->statementMetadata();

    expect($dtos)->toHaveCount(11);
    expect($summary?->closingBalanceCurrency)->toBe('EUR');
    expect($summary?->closingBalanceMinor)->toBe(paypalFeeColumnTotal(PAYPAL_FEE_WALLET, 'Netto'));
    expect($summary?->closingBalanceMinor)->toBe(1106);

    // What the adapter published before the fee became a row of its own, and
    // the whole distance between the two is the fee column.
    expect(paypalFeeColumnTotal(PAYPAL_FEE_WALLET, 'Bruto'))->toBe(1500);
    expect(paypalFeeColumnTotal(PAYPAL_FEE_WALLET, 'Kosten'))->toBe(-394);
});

it('names the PayPal transaction both of its rows came out of', function (): void {
    $rows = paypalFeeRowsFor(PAYPAL_FEE_WALLET, 'O-00000000000000102');

    expect($rows)->toHaveCount(2);
    expect($rows[1]->sourceRef)->toBe($rows[0]->sourceRef);
    expect($rows[1]->rawPayload['fee_of'])->toBe('O-00000000000000102');
    expect($rows[1]->description)->toBe('Kosten / Google Cloud EMEA Limited');
    expect($rows[1]->counterpartyName)->toBe($rows[0]->counterpartyName);
    expect($rows[1]->postedAt->toDateString())->toBe($rows[0]->postedAt->toDateString());
    expect($rows[1]->sourceRowIndex)->toBe($rows[0]->sourceRowIndex + 1);
});

// Two subscriptions charged on one day at one price. Their fees agree in every
// column the dedup tuple reads except the occurrence ordinal, which is counted
// over the file — so a second import numbers them the same way and neither is
// mistaken for the other, nor for a row already stored.
it('keeps two identical fees charged on the same day apart', function (): void {
    $first = paypalFeeRowsFor(PAYPAL_FEE_WALLET, 'O-00000000000000105');
    $second = paypalFeeRowsFor(PAYPAL_FEE_WALLET, 'O-00000000000000106');

    expect($first[1]->amountMinor)->toBe(-60);
    expect($second[1]->amountMinor)->toBe(-60);
    expect($first[1]->sourceRef)->not->toBe($second[1]->sourceRef);
    expect($first[1]->sourceRowIndex)->not->toBe($second[1]->sourceRowIndex);
});

// The conversion legs restate the payment; nothing in the file restates the
// fee. Deriving it at the rate those legs imply would be inventing a rate,
// which is forbidden outright — where none is to be had the original currency
// stands — so the fee stays in the denomination PayPal wrote it in.
it('states a converted payment fee in the currency the file states it in', function (): void {
    $rows = paypalFeeRowsFor(PAYPAL_FEE_CONVERTED, 'O-00000000000000202');

    expect($rows)->toHaveCount(2);
    expect($rows[0]->currency)->toBe('USD');
    expect($rows[0]->settledCurrency)->toBe('GBP');
    expect($rows[1]->currency)->toBe('USD');
    expect($rows[1]->amountMinor)->toBe(-50);
    expect($rows[1]->settledAmountMinor)->toBeNull();
});

// /reconcile reads a closing balance as a target the reader is asked to close,
// so a file naming two denominations must publish none rather than one that
// only half the rows sum into.
it('publishes no closing balance for a wallet whose fee is in another denomination', function (): void {
    paypalFeeParsed(PAYPAL_FEE_CONVERTED);
    $summary = $this->adapter->statementMetadata();

    expect($summary)->not->toBeNull();
    expect($summary?->closingBalanceMinor)->toBeNull();
    expect($summary?->closingBalanceCurrency)->toBeNull();
});

// The rollup names the derived row after the fee column's own header and the
// type map types it from that same string. They live in two classes that
// cannot import each other across the module boundary, so nothing but this
// holds them in step.
it('types the row it names after the fee column as a fee', function (): void {
    $columns = new PaypalCsvColumnMap;
    $events = new PaypalCsvEventTypeMap;

    foreach (PaypalCsvLanguageProfile::supported() as $language) {
        $header = $columns->header('fee', $language);

        expect($header)->not->toBeNull();
        expect($events->transactionType((string) $header, $language))->toBe(TransactionType::Fee);
    }
});
