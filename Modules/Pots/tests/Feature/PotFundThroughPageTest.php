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

/*
 * Funding a pot through the page the way the button does it: set
 * operationPotId + operationKind (what the Storten button's $wire.set pair
 * does), then the amount, then fundPot().
 *
 * Driven on a phone with EUR 3.754,64 unallocated on the account, a EUR 50,00
 * deposit left pot_movements empty and the pot reading EUR 0,00.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'potfund-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'potfund-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/potfund.xml',
        'sha256' => hash('sha256', 'potfund-'.bin2hex(random_bytes(6))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // Plenty unallocated, mirroring the device: EUR 3.754,64.
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 375464,
        'currency' => 'EUR',
        'settled_amount_minor' => 375464,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Salary',
        'counterparty_normalized' => 'salary',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 9001,
        'fingerprint' => str_pad('potfund', 64, '0', STR_PAD_LEFT),
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

it('writes a movement when a pot is funded through the page', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '50,00')
        ->call('fundPot')
        ->assertSet('errorAmount', '');

    $movements = DB::table('pot_movements')->where('pot_id', $this->pot->id)->get();

    expect($movements)->toHaveCount(1)
        ->and((int) $movements->first()->amount_minor)->toBe(5000)
        ->and($movements->first()->kind)->toBe('fund');
});

it('shows the funded amount on the pot afterwards', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '50,00')
        ->call('fundPot');

    $groups = Livewire::test(PotsPage::class)->viewData('groups');

    $funded = collect($groups)
        ->flatMap(static fn (iterable $pots): array => is_array($pots) ? $pots : iterator_to_array($pots))
        ->firstWhere('id', $this->pot->id);

    expect($funded)->not->toBeNull()
        ->and($funded->balanceMinor)->toBe(5000);
});

it('refuses more than the account has unallocated, and says so', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '99.999,00')
        ->call('fundPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '');

    expect(DB::table('pot_movements')->count())->toBe(0);
});
