<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Public\Exceptions\InsufficientUnallocatedException;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

// `accounts.default_currency` is mutable after the pots are made, and `pots.currency`
// is frozen at creation, so one account genuinely holds pots in a currency it no
// longer reports. Allocated summed `pot_movements.amount_minor` across all of them
// and printed the total under the account's new sign: EUR 2.700,00 of pots read as
// "allocated ¥270.000" beside a real balance of ¥285.000, and the ¥15.000 that left
// over was the ceiling every fund was weighed against.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    $this->user = User::create([
        'username' => 'pot-relabel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    // The baseline is what moves on a relabel: it opens the line of whatever the
    // account is denominated in, while the rows keep the currency they landed in.
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Revolut',
        'slug' => 'pot-relabel-revolut',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 285_000,
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/pot-relabel.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->subDays(5)->toDateString(),
        'booked_at' => CarbonImmutable::now()->subDays(5)->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->subDays(5)->toDateString(),
        'amount_minor' => 375_714,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => 375_714,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'Salary',
        'counterparty_normalized' => 'salary',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 4242,
        'fingerprint' => str_pad('relabel', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $writer = app(PotWriter::class);
    $this->rent = $writer->save($this->user, 'Rent', '2000,00', $this->account->id, null, null);
    $this->holiday = $writer->save($this->user, 'Holiday', '700,00', $this->account->id, null, null);

    $this->relabelToYen = function (): void {
        Livewire::test(AccountCurrencyEditor::class, [
            'accountId' => $this->account->id,
            'accountName' => $this->account->name,
            'currency' => Currency::Eur->value,
        ])
            ->set('currency', Currency::Jpy->value)
            ->call('save')
            ->assertSet('showingRelabelBanner', true)
            ->call('relabelAnyway')
            ->assertSet('storedCurrency', Currency::Jpy->value);
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('never reports another currency\'s allocation under the account\'s own sign', function (): void {
    $query = app(PotBalanceQuery::class);

    $before = $query->reconciliationForAccount($this->account->id, $this->user);
    expect($before->currency)->toBe(Currency::Eur->value)
        ->and($before->realBalanceMinor)->toBe(660_714)
        ->and($before->allocatedMinor)->toBe(270_000)
        ->and($before->unallocatedMinor)->toBe(390_714);

    ($this->relabelToYen)();

    $after = $query->reconciliationForAccount($this->account->id, $this->user);

    expect($after->currency)->toBe(Currency::Jpy->value)
        ->and($after->realBalanceMinor)->toBe(285_000)
        ->and($after->allocatedMinor)->toBe(0)
        ->and($after->unallocatedMinor)->toBe(285_000)
        ->and($after->isOverAllocated)->toBeFalse();
});

it('reconciles every currency the account still holds pots in, each under its own sign', function (): void {
    ($this->relabelToYen)();

    $rows = app(PotBalanceQuery::class)->reconciliationsForAccount($this->account->id, $this->user);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->currency)->toBe(Currency::Jpy->value)
        ->and($rows[0]->allocatedMinor)->toBe(0)
        ->and($rows[0]->unallocatedMinor)->toBe(285_000)
        ->and($rows[1]->currency)->toBe(Currency::Eur->value)
        ->and($rows[1]->realBalanceMinor)->toBe(375_714)
        ->and($rows[1]->allocatedMinor)->toBe(270_000)
        ->and($rows[1]->unallocatedMinor)->toBe(105_714);

    $html = (string) Livewire::test(PotsPage::class)->html();

    expect($html)->toContain(Money::ofMinor(270_000, Currency::Eur->value)->format())
        ->and($html)->toContain(Money::ofMinor(105_714, Currency::Eur->value)->format())
        ->and($html)->toContain(Money::ofMinor(285_000, Currency::Jpy->value)->format())
        ->and($html)->not->toContain(Money::ofMinor(270_000, Currency::Jpy->value)->format());
});

it('weighs a fund against the pot\'s own currency, not the account\'s new one', function (): void {
    ($this->relabelToYen)();

    app(PotWriter::class)->fund($this->user, $this->rent->id, '1000,00');

    expect((int) DB::table('pot_movements')->where('pot_id', $this->rent->id)->sum('amount_minor'))
        ->toBe(300_000);

    $rows = app(PotBalanceQuery::class)->reconciliationsForAccount($this->account->id, $this->user);

    expect($rows[1]->allocatedMinor)->toBe(370_000)
        ->and($rows[1]->unallocatedMinor)->toBe(5_714);
});

it('still refuses a fund beyond what that currency has unallocated', function (): void {
    ($this->relabelToYen)();

    expect(fn () => app(PotWriter::class)->fund($this->user, $this->rent->id, '1100,00'))
        ->toThrow(InsufficientUnallocatedException::class);

    expect((int) DB::table('pot_movements')->where('pot_id', $this->rent->id)->sum('amount_minor'))
        ->toBe(200_000);
});

it('quotes the refused ceiling in the pot\'s own currency', function (): void {
    ($this->relabelToYen)();

    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->rent->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '2000,00')
        ->call('fundPot')
        ->assertSet('errorAmountLimitMinor', 105_714)
        ->assertSet('errorAmountLimitCurrency', Currency::Eur->value);

    expect((int) DB::table('pot_movements')->where('pot_id', $this->rent->id)->sum('amount_minor'))
        ->toBe(200_000);
});

it('opens a pot in the new currency against the money that currency actually holds', function (): void {
    ($this->relabelToYen)();

    $ryokan = app(PotWriter::class)->save($this->user, 'Ryokan', '250.000', $this->account->id, null, null);

    expect($ryokan->currency)->toBe(Currency::Jpy->value)
        ->and((int) DB::table('pot_movements')->where('pot_id', $ryokan->id)->sum('amount_minor'))
        ->toBe(250_000);

    $rows = app(PotBalanceQuery::class)->reconciliationsForAccount($this->account->id, $this->user);

    expect($rows[0]->currency)->toBe(Currency::Jpy->value)
        ->and($rows[0]->allocatedMinor)->toBe(250_000)
        ->and($rows[0]->unallocatedMinor)->toBe(35_000);
});
