<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\FingerprintComposer;

// Measured on a phone against this same three-row export: Revolut's Balance
// column moved -94.00 while the account read -92.75, and the 1.25 between them
// was the Fee column nothing consumed.
const REVOLUT_FEE_EXPORT = __DIR__.'/../../../Ingestion/tests/fixtures/csv/revolut-fee-sample.csv';

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'revolut-fee',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Revolut Current',
        'slug' => 'revolut-fee-fixture',
        'kind' => AccountKind::Bank->value,
        'iban' => 'REVOLUT',
        'default_currency' => Currency::Eur->value,
    ]);

    $this->importer = app(RunsImports::class);
});

function revolutExportBankMoveMinor(): int
{
    $parser = new GenericCsvAmountParser;
    $handle = fopen(REVOLUT_FEE_EXPORT, 'r');
    expect($handle)->not->toBeFalse();

    $header = fgetcsv($handle, 0, ',', '"', '');
    expect($header)->toBeArray();
    $at = array_flip($header);

    $opening = null;
    $closing = 0;
    while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $balance = $parser->parseMinor($record[$at['Balance']], '.');
        $opening ??= $balance
            - $parser->parseMinor($record[$at['Amount']], '.')
            + $parser->parseMinor($record[$at['Fee']], '.');
        $closing = $balance;
    }
    fclose($handle);

    return $closing - (int) $opening;
}

it('lands the account on the balance the export says it landed on', function (): void {
    $result = $this->importer->runAndConfirm(REVOLUT_FEE_EXPORT, 'revolut-csv', $this->user);
    expect($result->inserted)->toBe(3);

    $balance = app(AccountBalanceQuery::class)->currentBalance($this->account->id, $this->user);

    expect($balance->in(Currency::Eur->value))->toBe(revolutExportBankMoveMinor());
    expect($balance->in(Currency::Eur->value))->toBe(-9400);
});

it('still reports the merchant charge as the native amount', function (): void {
    $this->importer->runAndConfirm(REVOLUT_FEE_EXPORT, 'revolut-csv', $this->user);

    expect((int) Transaction::query()->sum('amount_minor'))->toBe(-9275);
    expect((int) Transaction::query()->sum('settled_amount_minor'))->toBe(-9400);
});

// The dedup tuple reads the native amount and currency, so moving the fee into
// the settled pair cannot re-key a row a reader already has. Without this the
// only warning would be a second copy of somebody's ledger.
it('keys every row on the native amount, so a re-import still dedups', function (): void {
    $this->importer->runAndConfirm(REVOLUT_FEE_EXPORT, 'revolut-csv', $this->user);

    $composer = app(FingerprintComposer::class);
    foreach (Transaction::query()->get() as $row) {
        expect($row->fingerprint)->toBe($composer->composeTuple(
            $this->user->id,
            $this->account->id,
            $row->posted_at->toDateString(),
            $row->booked_at->toDateTimeString(),
            (int) $row->amount_minor,
            (string) $row->currency,
            (string) $row->counterparty_normalized,
        ));
    }

    $again = $this->importer->runAndConfirm(REVOLUT_FEE_EXPORT, 'revolut-csv', $this->user, 'second-pass.csv');
    expect($again->inserted)->toBe(0);
    expect(Transaction::query()->count())->toBe(3);
});
