<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

// Two devices used while apart both take the next autoincrement, and
// pot_movements declares no unique index to tell the two rows apart
// afterwards, so each device's deposit was refused by the other as an
// idempotent replay. Minted rather than derived: a second deposit of the same
// amount on the same day is a second deposit, and an id computed from the
// row's content would merge the two and lose the money.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'potid-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'potid-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.random_int(1000000000, 9999999999),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/potid.xml',
        'sha256' => hash('sha256', 'potid-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $run->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 5000000,
        'currency' => 'EUR',
        'settled_amount_minor' => 5000000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Salaris',
        'counterparty_normalized' => 'salaris',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 7001,
        'fingerprint' => hash('sha256', 'potid-credit-'.$this->user->id),
        'fingerprint_version' => 1,
    ]);

    $this->pot = Pot::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Vakantie',
        'target_minor' => 200000,
        'currency' => 'EUR',
        'status' => 'active',
    ]);
});

it('does not give the next movement the id after the last one', function (): void {
    $writer = app(PotWriter::class);

    $writer->fund($this->user, (int) $this->pot->id, '10,00');
    $writer->fund($this->user, (int) $this->pot->id, '10,00');

    $ids = DB::table('pot_movements')->orderBy('created_at')->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids[1])->not->toBe($ids[0] + 1)
        ->and($ids[1])->not->toBe($ids[0]);
});

// Two identical deposits are two deposits. A content-derived id would give
// them one row, which is the failure this must not trade up to.
it('keeps two identical deposits apart', function (): void {
    $writer = app(PotWriter::class);

    $writer->fund($this->user, (int) $this->pot->id, '25,00');
    $writer->fund($this->user, (int) $this->pot->id, '25,00');

    expect(DB::table('pot_movements')->count())->toBe(2)
        ->and(DB::table('pot_movements')->distinct()->count('id'))->toBe(2)
        ->and(DB::table('pot_movements')->sum('amount_minor'))->toBe(5000);
});

it('funds a pot whose id crossed the browser as a string', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', (string) $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '10,00')
        ->call('fundPot')
        ->assertSet('errorAmount', '');

    expect(DB::table('pot_movements')->count())->toBe(1);
});

it('opens the edit form for a pot whose id crossed the browser as a string', function (): void {
    Livewire::test(PotsPage::class)
        ->call('openEdit', (string) $this->pot->id)
        ->assertSet('editPotId', (int) $this->pot->id)
        ->assertSet('name', 'Vakantie');
});
