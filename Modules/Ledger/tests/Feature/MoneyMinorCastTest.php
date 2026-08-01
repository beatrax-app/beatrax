<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\Money;

beforeEach(function (): void {
    $this->account = Account::create([
        'name' => 'ASN', 'slug' => 'asn', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

it('exposes amount as a Money value object', function (): void {
    $tx = Transaction::create([
        'account_id' => $this->account->id, 'type' => 'expense',
        'posted_at' => '2026-05-03', 'booked_at' => '2026-05-03 12:00:00', 'value_date' => '2026-05-03',
        'amount_minor' => -1299, 'currency' => 'EUR',
        'settled_amount_minor' => -1299, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah amsterdam', 'normalization_version' => 1,
        'source_format' => 'asn-csv', 'import_run_id' => $this->importRun->id, 'source_row_index' => 0,
        'fingerprint' => str_repeat('a', 64), 'fingerprint_version' => 1,
    ]);

    $reloaded = Transaction::find($tx->id);

    expect($reloaded->amount)->toBeInstanceOf(Money::class);
    expect($reloaded->amount->toMinor())->toBe(-1299);
    expect($reloaded->amount->currency())->toBe('EUR');
});

it('round-trips Money writes through MoneyMinorCast', function (): void {
    $tx = Transaction::create([
        'account_id' => $this->account->id, 'type' => 'expense',
        'posted_at' => '2026-05-03', 'booked_at' => '2026-05-03 12:00:00', 'value_date' => '2026-05-03',
        'amount_minor' => -1299, 'currency' => 'EUR',
        'settled_amount_minor' => -1299, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah amsterdam', 'normalization_version' => 1,
        'source_format' => 'asn-csv', 'import_run_id' => $this->importRun->id, 'source_row_index' => 0,
        'fingerprint' => str_repeat('a', 64), 'fingerprint_version' => 1,
    ]);

    $reloaded = Transaction::find($tx->id);
    $reloaded->amount = Money::ofMinor(-500, 'EUR');
    $reloaded->save();

    $again = Transaction::find($tx->id);
    expect($again->amount_minor)->toBe(-500);
    expect($again->currency)->toBe('EUR');
    expect($again->amount->toMinor())->toBe(-500);
});

it('round-trips the settled-amount pair via the parameterised cast', function (): void {
    $tx = Transaction::create([
        'account_id' => $this->account->id, 'type' => 'expense',
        'posted_at' => '2026-05-03', 'booked_at' => '2026-05-03 12:00:00', 'value_date' => '2026-05-03',
        'amount_minor' => -999, 'currency' => 'USD',
        'settled_amount_minor' => -880, 'settled_currency' => 'EUR',
        'fx_rate_used' => '0.88090000',
        'counterparty_normalized' => 'google play', 'normalization_version' => 1,
        'source_format' => 'asn-csv', 'import_run_id' => $this->importRun->id, 'source_row_index' => 0,
        'fingerprint' => str_repeat('b', 64), 'fingerprint_version' => 1,
    ]);

    $reloaded = Transaction::find($tx->id);

    expect($reloaded->amount->toMinor())->toBe(-999);
    expect($reloaded->amount->currency())->toBe('USD');
    expect($reloaded->settled_amount->toMinor())->toBe(-880);
    expect($reloaded->settled_amount->currency())->toBe('EUR');
});

it('rejects non-Money writes', function (): void {
    $tx = Transaction::create([
        'account_id' => $this->account->id, 'type' => 'expense',
        'posted_at' => '2026-05-03', 'booked_at' => '2026-05-03 12:00:00', 'value_date' => '2026-05-03',
        'amount_minor' => -1299, 'currency' => 'EUR',
        'settled_amount_minor' => -1299, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ah', 'normalization_version' => 1,
        'source_format' => 'asn-csv', 'import_run_id' => $this->importRun->id, 'source_row_index' => 0,
        'fingerprint' => str_repeat('c', 64), 'fingerprint_version' => 1,
    ]);

    expect(function () use ($tx): void {
        $tx->amount = 'not a Money';
        $tx->save();
    })->toThrow(InvalidArgumentException::class, 'Money cast expects');
});
