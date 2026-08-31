<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

// The card shows the last ten movements and stopped there with no sign that an
// eleventh existed, so a pot's history read as complete when it was not.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/hist.xml',
        'sha256' => str_repeat('6', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 100000,
        'currency' => 'EUR',
        'settled_amount_minor' => 100000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Salaris',
        'counterparty_normalized' => 'salaris',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('hist', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $this->pot = app(PotWriter::class)->save($this->user, 'Boodschappen', null, $this->account->id, null, null);
});

it('says how much of the history the last ten movements are', function (): void {
    for ($i = 0; $i < 12; $i++) {
        app(PotWriter::class)->fund($this->user, $this->pot->id, '1,00');
    }

    $row = app(PotBalanceQuery::class)->forUser($this->user)[0];

    expect($row->recentMovements)->toHaveCount(10)
        ->and($row->movementCount)->toBe(12)
        ->and($row->hasOlderMovements())->toBeTrue();

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('pots::messages.history.truncated', ['shown' => '10', 'count' => '12']));
});

it('says nothing about older movements when the list is the whole history', function (): void {
    for ($i = 0; $i < 3; $i++) {
        app(PotWriter::class)->fund($this->user, $this->pot->id, '1,00');
    }

    $row = app(PotBalanceQuery::class)->forUser($this->user)[0];

    expect($row->movementCount)->toBe(3)
        ->and($row->hasOlderMovements())->toBeFalse();

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertDontSee(Lang::get('pots::messages.history.truncated', ['shown' => '3', 'count' => '3']));
});

// Both counts read the reader's own number formatting, and both nouns agree
// with the number beside them, in every locale the app ships.
it('agrees with its own count in the archived disclosure', function (): void {
    app(PotWriter::class)->archive($this->user, $this->pot->id);

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee(Lang::choice('pots::messages.archived.toggle', 1))
        ->assertDontSee('Archived pot (1)|Archived pots (1)');

    expect(Lang::choice('pots::messages.archived.toggle', 1))->toBe('Archived pot (1)')
        ->and(Lang::choice('pots::messages.archived.toggle', 4))->toBe('Archived pots (4)');
});
