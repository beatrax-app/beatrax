<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;

// The refusal quotes the same figure the modal prints above the box. With
// EUR 241,09 available, 300 was refused and correcting to 100 left the message
// standing over a number it no longer described.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'potclear-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'potclear-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/potclear.xml',
        'sha256' => hash('sha256', 'potclear-'.bin2hex(random_bytes(6))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // EUR 241,09 unallocated, the figure the device showed.
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 24109,
        'currency' => 'EUR',
        'settled_amount_minor' => 24109,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Salary',
        'counterparty_normalized' => 'salary',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 9101,
        'fingerprint' => str_pad('potclear', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $this->pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Annual insurance',
        'currency' => 'EUR',
        'status' => 'active',
    ]);
});

it('drops the funding refusal once the amount is corrected', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '300,00')
        ->call('fundPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '')
        ->set('operationAmount', '100,00')
        ->assertSet('errorAmount', '');
});

it('keeps the funding refusal while the corrected amount still exceeds the balance', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '300,00')
        ->call('fundPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '')
        ->set('operationAmount', '500,00')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '');
});

it('keeps the funding refusal while the box holds something that is not an amount', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '300,00')
        ->call('fundPot')
        ->set('operationAmount', 'later')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '');
});

it('funds the pot on the next submit after a refusal', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '300,00')
        ->call('fundPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '')
        ->set('operationAmount', '100,00')
        ->call('fundPot')
        ->assertSet('errorAmount', '');

    $movements = DB::table('pot_movements')->where('pot_id', $this->pot->id)->get();

    expect($movements)->toHaveCount(1)
        ->and((int) $movements->first()->amount_minor)->toBe(10000);
});

it('drops the withdrawal refusal once the amount is corrected', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '100,00')
        ->call('fundPot');

    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'withdraw')
        ->set('operationAmount', '150,00')
        ->call('withdrawPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '')
        ->set('operationAmount', '80,00')
        ->assertSet('errorAmount', '');
});

it('drops the create-form refusal once the initial amount is corrected', function (): void {
    Livewire::test(PotsPage::class)
        ->set('name', 'Holiday')
        ->set('accountId', (string) $this->account->id)
        ->set('amount', '300,00')
        ->call('createPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '')
        ->set('amount', '100,00')
        ->assertSet('errorAmount', '');
});

it('keeps the create-form refusal while the initial amount still exceeds unallocated', function (): void {
    Livewire::test(PotsPage::class)
        ->set('name', 'Holiday')
        ->set('accountId', (string) $this->account->id)
        ->set('amount', '300,00')
        ->call('createPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '')
        ->set('amount', '400,00')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '');
});

it('drops the name rejection once a name is typed', function (): void {
    Livewire::test(PotsPage::class)
        ->set('accountId', (string) $this->account->id)
        ->set('name', '')
        ->call('createPot')
        ->assertSet('errorName', fn (string $v): bool => $v !== '')
        ->set('name', 'Holiday')
        ->assertSet('errorName', '');
});

it('syncs the boxes it must not contradict before the next submit', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Pots/Resources/views/livewire/pots-page.blade.php')
    );

    // A deferred binding reaches the server only on submit, so the hook that
    // re-tests the refusal would not run until the refusal was already gone.
    expect($blade)->toContain('wire:model.blur="operationAmount"')
        ->and($blade)->toContain('wire:model.blur="amount"')
        ->and($blade)->toContain('wire:model.blur="name"')
        ->and($blade)->toContain('wire:model.blur="accountId"');
});
