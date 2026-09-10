<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;
use Modules\Pots\Internal\Exceptions\CrossCurrencyTransferException;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

// `accounts.default_currency` is mutable and `pots.currency` is frozen at
// creation, so one account holds pots in two denominations. transfer() read the
// currency off the SOURCE pot and stamped it on both legs, so the target pot
// took the source's minor units under its own sign.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    $this->user = User::create([
        'username' => 'pot-move-scale',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Revolut',
        'slug' => 'pot-move-scale-revolut',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 285_000,
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/pot-move-scale.xml',
        'sha256' => str_repeat('c', 64),
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
        'source_row_index' => 5151,
        'fingerprint' => str_pad('movescale', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $writer = app(PotWriter::class);
    $this->rent = $writer->save($this->user, 'Rent', '2000,00', $this->account->id, null, null);
    $this->holiday = $writer->save($this->user, 'Holiday', '700,00', $this->account->id, null, null);

    Livewire::test(AccountCurrencyEditor::class, [
        'accountId' => $this->account->id,
        'accountName' => $this->account->name,
        'currency' => Currency::Eur->value,
    ])
        ->set('currency', Currency::Jpy->value)
        ->call('save')
        ->call('relabelAnyway')
        ->assertSet('storedCurrency', Currency::Jpy->value);

    $this->ryokan = $writer->save($this->user, 'Ryokan', '250.000', $this->account->id, null, null);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('refuses a move between two pots holding different currencies', function (): void {
    expect(fn () => app(PotWriter::class)->transfer($this->user, $this->ryokan->id, $this->rent->id, '50.000'))
        ->toThrow(CrossCurrencyTransferException::class);

    expect(DB::table('pot_movements')->where('pot_id', $this->rent->id)->count())->toBe(1);
});

it('never reports a pot balance summed across two denominations', function (): void {
    DB::table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $this->rent->id,
        'counterpart_pot_id' => null,
        'amount_minor' => 50_000,
        'currency' => Currency::Jpy->value,
        'kind' => PotMovementKind::TransferIn->value,
        'memo' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    expect(app(PotBalanceQuery::class)->balanceForPot($this->rent->id, $this->user))->toBe(200_000);
});

it('keeps every pot row adding up to the reconciliation line beside it', function (): void {
    $writer = app(PotWriter::class);
    $query = app(PotBalanceQuery::class);

    $writer->transfer($this->user, $this->rent->id, $this->holiday->id, '500,00');

    try {
        $writer->transfer($this->user, $this->ryokan->id, $this->rent->id, '50.000');
    } catch (CrossCurrencyTransferException) {
        // The refusal is asserted above; here it is only the state it leaves.
    }

    $byCurrency = [];
    foreach ($query->forUser($this->user) as $row) {
        $byCurrency[$row->currency] = ($byCurrency[$row->currency] ?? 0) + $row->balanceMinor;
    }

    foreach ($query->reconciliationsForAccount($this->account->id, $this->user) as $line) {
        expect($byCurrency[$line->currency] ?? 0)->toBe($line->allocatedMinor);
    }
});

it('never offers a pot in another currency as a move target', function (): void {
    $rows = app(PotBalanceQuery::class)->forUser($this->user);
    $source = PotRow::withId($rows, $this->ryokan->id);

    expect($source)->not->toBeNull()
        ->and($source->moveTargetsAmong($rows))->toBe([]);
});

it('names the currency a move could not reach, and does not blame the account', function (): void {
    $refusal = Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->ryokan->id)
        ->set('transferTargetPotId', (string) $this->rent->id)
        ->set('operationAmount', '50.000')
        ->call('movePot')
        ->get('errorTarget');

    expect($refusal)->toBe(Lang::get('pots::messages.errors.move_cross_currency', [
        'name' => 'Rent',
        'currency' => Currency::Eur->value,
    ]))
        ->and($refusal)->not->toBe(Lang::get('pots::messages.errors.move_cross_account', [
            'name' => 'Rent',
            'account' => 'Revolut',
        ]));
});
